/**
 * cmf-media 浏览器直传管线：图片与视频共用同一套协议。
 *
 * 顺序：算内容指纹 → check（同内容秒传）→ sign（拿直传凭证）→ 浏览器直传 → callback 建档。
 * 云存储桶未配 CORS 时（直传被拦，status 0）才退回应用服务器中转端点。
 *
 * 端点由 TinyMceServiceProvider 注入到 profile 的 custom_configs.cmf_media_upload，
 * 全部是同源相对路径（编辑器页可能挂在别的域名/IP 下，绝对地址会让 CSRF 与 Worker 跨源）。
 */
(function () {
    "use strict";

    var DEFAULT_FALLBACK = { video: "/cmf-rich-content/video", image: "/cmf-rich-content/image" };
    var DEFAULT_RULE = { video: "video", image: "editor" };

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    function notify(editor, text, type) {
        editor.notificationManager.open({
            text: text,
            type: type || "info",
            timeout: type === "error" ? 6000 : 3000,
        });
    }

    // 服务端已经拒绝引号/尖括号，这里再兜一层：万一规则放宽或数据来自别处，
    // 也不会把属性拼断成新的标签。
    function escapeHtml(value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function videoHtml(src, id, width, height) {
        // TinyMCE 对 <video> 会套一层 media 预览对象并按 300x150 兜底，这里显式给默认尺寸，
        // 避免插进来就是一个 300x150 的小窗；CSS 侧仍是 max-width:100%，不会溢出。
        var attrs = [
            'controls="controls"',
            'src="' + escapeHtml(src) + '"',
            'width="' + (width || 640) + '"',
            'height="' + (height || 360) + '"',
        ];

        if (id) {
            attrs.push('data-cmf-media="' + id + '"');
        }

        // height:auto 让高度跟随视频原始比例（width/height 属性只给 TinyMCE 预览兜底用），
        // 否则 16:9 的定高会把更宽的视频在编辑器里压出上下白边。
        attrs.push('style="max-width:100%;height:auto"');

        return "<video " + attrs.join(" ") + "></video>";
    }

    /** 服务端注入的直传端点（cmf_video_upload）；缺失时按 cmf-media 默认路由兜底。 */
    function resolveEndpoints(editor, kind) {
        var image = kind === "image";
        var configured = null;

        try {
            configured = editor.getParam("cmf_media_upload", null, "object");
        } catch (error) {
            configured = null;
        }

        configured = configured || window.cmfMediaUpload || {};

        return {
            check: configured.check || "/cmf-media/check",
            sign: configured.sign || "/cmf-media/sign",
            callback: configured.callback || "/cmf-media/callback",
            fallback: (image ? configured.image_fallback : configured.fallback) || DEFAULT_FALLBACK[kind] || "",
            worker: configured.hash_worker || "",
            spark: configured.spark_md5 || "",
            rule: (image ? configured.image_rule : configured.rule) || DEFAULT_RULE[kind] || "editor",
            maxSize: Number(configured.max_size) || 0,
        };
    }

    function firstError(data) {
        if (!data || !data.errors) {
            return "";
        }

        var keys = Object.keys(data.errors);

        return keys.length ? String(data.errors[keys[0]][0] || "") : "";
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": csrfToken(),
            },
            body: JSON.stringify(payload),
        }).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (data) {
                    if (!data || typeof data !== "object" || Array.isArray(data)) {
                        var malformed = new Error("服务端响应格式不正确");

                        malformed.status = response.status;
                        throw malformed;
                    }

                    if (response.ok) {
                        return data;
                    }

                    var error = new Error(firstError(data) || data.message || "请求失败（HTTP " + response.status + "）");
                    error.status = response.status;

                    throw error;
                });
        });
    }

    /** Worker 不可用时的主线程兜底：8MB 分片读，内存占用恒定。 */
    function loadSpark(endpoints) {
        if (window.SparkMD5) {
            return Promise.resolve(window.SparkMD5);
        }

        if (!endpoints.spark) {
            return Promise.reject(new Error("缺少文件指纹组件"));
        }

        return new Promise(function (resolve, reject) {
            var script = document.createElement("script");

            script.src = endpoints.spark;
            script.onload = function () {
                window.SparkMD5 ? resolve(window.SparkMD5) : reject(new Error("文件指纹组件加载失败"));
            };
            script.onerror = function () {
                reject(new Error("文件指纹组件加载失败"));
            };
            document.head.appendChild(script);
        });
    }

    function hashInPage(endpoints, file, onProgress) {
        return loadSpark(endpoints).then(function (SparkMD5) {
            return new Promise(function (resolve, reject) {
                var chunkSize = 8 * 1024 * 1024;
                var offset = 0;
                var spark = new SparkMD5.ArrayBuffer();
                var reader = new FileReader();

                function readNext() {
                    reader.readAsArrayBuffer(file.slice(offset, offset + chunkSize));
                }

                reader.onload = function (event) {
                    spark.append(event.target.result);
                    offset += chunkSize;
                    onProgress(file.size === 0 ? 1 : Math.min(1, offset / file.size));

                    if (offset < file.size) {
                        readNext();
                    } else {
                        resolve(spark.end());
                    }
                };
                reader.onerror = function () {
                    reject(new Error("读取文件失败"));
                };

                readNext();
            });
        });
    }

    /** 内容指纹（md5）：与服务端 check/callback 的 hash 同口径，决定秒传与去重。 */
    function hashFile(endpoints, file, onProgress) {
        if (!endpoints.worker || typeof Worker === "undefined") {
            return hashInPage(endpoints, file, onProgress);
        }

        return new Promise(function (resolve, reject) {
            var worker = new Worker(endpoints.worker);

            worker.onmessage = function (event) {
                var data = event.data || {};

                if (data.error) {
                    worker.terminate();
                    reject(new Error("计算文件指纹失败：" + data.error));

                    return;
                }

                if (typeof data.progress === "number") {
                    onProgress(data.progress);

                    return;
                }

                if (data.hash) {
                    worker.terminate();
                    resolve(data.hash);
                }
            };
            worker.onerror = function (event) {
                worker.terminate();
                reject(new Error("计算文件指纹失败：" + (event.message || "Worker 异常")));
            };
            worker.postMessage({ file: file, sparkUrl: endpoints.spark });
        }).catch(function () {
            // Worker 被 CSP 拦、脚本 404、importScripts(spark) 失败时退回主线程：大文件慢一些，但上传还能用。
            return hashInPage(endpoints, file, onProgress);
        });
    }

    /** 直传：云存储 PUT 签名 URL；local 驱动 POST 到应用服务器 upload 端点。 */
    function transfer(file, credential, onProgress) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            var target = credential.upload_url;

            if (credential.local === true && typeof target === "string" && /^https?:/i.test(target)) {
                var parsed = new URL(target, window.location.href);

                target = parsed.pathname + parsed.search;
            }

            xhr.open(credential.method, target, true);

            Object.keys(credential.headers || {}).forEach(function (name) {
                xhr.setRequestHeader(name, credential.headers[name]);
            });

            xhr.upload.onprogress = function (event) {
                if (event.lengthComputable) {
                    onProgress(event.loaded / event.total);
                }
            };
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    var body = null;

                    try {
                        body = JSON.parse(xhr.responseText);
                    } catch (error) {
                        body = null; // 云存储 PUT 成功响应体为空
                    }

                    resolve(body || {});

                    return;
                }

                var message = "上传失败（HTTP " + xhr.status + "）";

                try {
                    var parsed = JSON.parse(xhr.responseText);
                    message = firstError(parsed) || parsed.message || message;
                } catch (error) {
                    // 云存储错误响应不是 JSON
                }

                var failure = new Error(message);

                failure.status = xhr.status;
                reject(failure);
            };
            xhr.onerror = function () {
                var failure = new Error("上传失败：网络或跨域错误");

                failure.status = 0;
                reject(failure);
            };

            if (credential.method === "POST") {
                var form = new FormData();

                Object.keys(credential.fields || {}).forEach(function (name) {
                    form.append(name, credential.fields[name]);
                });
                form.append("file", file);
                xhr.send(form);
            } else {
                xhr.send(file);
            }
        });
    }

    /** 校验响应里确实带 media（id + url），否则按给定文案抛错。 */
    function requireMedia(media, message) {
        if (!media || !media.id || !media.url) {
            throw new Error(message);
        }

        return media;
    }

    /** 服务器中转兜底：云存储未配 CORS 时用，受 PHP upload_max_filesize 约束。 */
    function uploadThroughServer(url, file, onProgress) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            var form = new FormData();

            form.append("file", file);
            xhr.open("POST", url, true);
            xhr.setRequestHeader("Accept", "application/json");
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.setRequestHeader("X-CSRF-TOKEN", csrfToken());
            xhr.upload.onprogress = function (event) {
                if (event.lengthComputable) {
                    onProgress(event.loaded / event.total);
                }
            };
            xhr.onload = function () {
                var body = null;

                try {
                    body = JSON.parse(xhr.responseText);
                } catch (error) {
                    body = null;
                }

                if (xhr.status >= 200 && xhr.status < 300 && body) {
                    resolve(body);

                    return;
                }

                reject(new Error((body && (firstError(body) || body.message)) || "上传失败（HTTP " + xhr.status + "）"));
            };
            xhr.onerror = function () {
                reject(new Error("上传失败：网络错误"));
            };
            xhr.send(form);
        });
    }

    /**
     * 上传一个文件，resolve 成 media（id / url）；kind 决定规则与兜底端点（video / image）。
     *
     * 顺序：算内容指纹 → check（同内容秒传）→ sign（拿直传凭证）→ 浏览器直传 → callback 建档。
     * local 驱动由 sign 直接返回应用服务器的 upload 端点，响应即建档结果，跳过 callback。
     */
    function upload(editor, file, kind, onProgress) {
        var endpoints = resolveEndpoints(editor, kind);
        var hash = "";

        function payload(extra) {
            var data = {
                hash: hash,
                name: file.name,
                mime: file.type || "application/octet-stream",
                size: file.size,
                rule: endpoints.rule,
            };

            Object.keys(extra || {}).forEach(function (key) {
                data[key] = extra[key];
            });

            return data;
        }

        return hashFile(endpoints, file, function (ratio) {
            onProgress("正在校验文件指纹…", ratio);
        })
            .then(function (value) {
                hash = value;

                return postJson(endpoints.check, payload()).then(
                    function (data) {
                        if (!data.media) {
                            throw new Error("秒传结果缺少媒体信息");
                        }

                        return { hit: true, media: data.media };
                    },
                    function (error) {
                        if (error.status === 404) {
                            return { hit: false };
                        }

                        throw error;
                    }
                );
            })
            .then(function (result) {
                if (result.hit) {
                    return result.media;
                }

                return postJson(endpoints.sign, payload()).then(function (credential) {
                    onProgress("正在上传…", 0);

                    return transfer(file, credential, function (ratio) {
                        onProgress("正在上传…", ratio);
                    })
                        .catch(function (error) {
                            // 只有云存储直传被 CORS 拦（status 0）才退回服务器中转：
                            // local 直传失败与 callback 建档失败都不该重传整个文件。
                            if (error.status === 0 && credential.local !== true && endpoints.fallback) {
                                onProgress("正在通过服务器中转…", 0);

                                return uploadThroughServer(endpoints.fallback, file, function (ratio) {
                                    onProgress("正在通过服务器中转…", ratio);
                                }).then(function (media) {
                                    // 中转端点自己就建了档，标记一下，后面不再走 callback。
                                    return { registered: media };
                                });
                            }

                            throw error;
                        })
                        .then(function (uploaded) {
                            // 中转兜底端点返回的就是建档后的 media。
                            if (uploaded.registered) {
                                return requireMedia(uploaded.registered, "上传结果缺少媒体信息");
                            }

                            // local 直传的 upload 端点响应即建档结果（{media:{…}}），跳过 callback。
                            if (credential.local === true) {
                                return requireMedia(uploaded.media || uploaded, "上传结果缺少媒体信息");
                            }

                            // 云存储直传的 PUT 成功响应体是空的（没有 media），记录只能由 callback 建。
                            onProgress("正在登记…", 1);

                            return postJson(endpoints.callback, payload({ path: credential.path })).then(function (data) {
                                return requireMedia(data.media || data, "登记结果缺少媒体信息");
                            });
                        });
                });
            });
    }


    window.CmfRichContentMediaUpload = {
        upload: upload,
        endpoints: resolveEndpoints,
        videoHtml: videoHtml,
    };
})();

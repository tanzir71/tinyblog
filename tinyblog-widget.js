/*!
 * TinyBlog Widget v0.1.0
 * One-line embed for TinyBlog feeds, posts, and subscribe boxes.
 * Repository: https://github.com/tanzir71/tinyblog
 */
(function () {
  "use strict";

  var DEFAULTS = {
    endpoint: "",
    site: "store-1",
    siteKey: "",
    widgetType: "feed",
    maxItems: 5,
    showExcerpt: true,
    theme: "light",
    accent: "#0a0a0a",
    locale: "en",
    container: "",
    canonicalUrl: ""
  };

  var listeners = {};
  var instances = [];
  var currentScript = document.currentScript;

  function on(eventName, callback) {
    if (typeof callback !== "function") return TinyBlogWidget;
    listeners[eventName] = listeners[eventName] || [];
    listeners[eventName].push(callback);
    return TinyBlogWidget;
  }

  function emit(eventName, payload) {
    (listeners[eventName] || []).forEach(function (callback) {
      try {
        callback(payload);
      } catch (error) {
        setTimeout(function () {
          throw error;
        }, 0);
      }
    });
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function safeUrl(url) {
    var raw = String(url || "").trim();
    if (!raw) return "";
    if (raw.charAt(0) === "/") return raw;
    try {
      var parsed = new URL(raw, window.location.href);
      if (parsed.protocol === "http:" || parsed.protocol === "https:") {
        return parsed.href;
      }
    } catch (error) {
      return "";
    }
    return "";
  }

  function sanitizeHtml(html) {
    var allowed = {
      p: [],
      a: ["href", "title"],
      strong: [],
      em: [],
      ul: [],
      ol: [],
      li: [],
      img: ["src", "alt"],
      blockquote: [],
      pre: [],
      code: ["class"],
      span: ["class"],
      br: []
    };
    var template = document.createElement("template");
    template.innerHTML = String(html || "");

    function cleanNode(node) {
      if (node.nodeType === Node.TEXT_NODE) {
        return document.createTextNode(node.nodeValue || "");
      }
      if (node.nodeType !== Node.ELEMENT_NODE) {
        return document.createTextNode("");
      }
      var tag = node.tagName.toLowerCase();
      if (!Object.prototype.hasOwnProperty.call(allowed, tag)) {
        return document.createTextNode(node.textContent || "");
      }
      var safe = document.createElement(tag);
      allowed[tag].forEach(function (attr) {
        var value = node.getAttribute(attr);
        if (!value) return;
        if ((attr === "href" || attr === "src") && !safeUrl(value)) return;
        if (attr === "class" && !/^(language-[a-z0-9_-]+|tok-key)$/i.test(value)) return;
        safe.setAttribute(attr, attr === "href" || attr === "src" ? safeUrl(value) : value.slice(0, 180));
      });
      if (tag === "a") {
        safe.setAttribute("rel", "nofollow noopener");
        safe.setAttribute("target", "_blank");
      }
      if (tag === "img") {
        safe.setAttribute("loading", "lazy");
      }
      Array.prototype.slice.call(node.childNodes).forEach(function (child) {
        safe.appendChild(cleanNode(child));
      });
      return safe;
    }

    var fragment = document.createDocumentFragment();
    Array.prototype.slice.call(template.content.childNodes).forEach(function (child) {
      fragment.appendChild(cleanNode(child));
    });
    var box = document.createElement("div");
    box.appendChild(fragment);
    return box.innerHTML;
  }

  function merge(target, source) {
    Object.keys(source || {}).forEach(function (key) {
      if (source[key] !== undefined && source[key] !== null) {
        target[key] = source[key];
      }
    });
    return target;
  }

  function parseScriptConfig(script) {
    if (!script) return {};
    var raw = script.getAttribute("data-tb-config");
    var config = {};
    if (raw) {
      try {
        config = JSON.parse(raw);
      } catch (error) {
        emit("error", { error: error, message: "Invalid data-tb-config JSON." });
      }
    }
    ["endpoint", "site", "siteKey", "widgetType", "maxItems", "showExcerpt", "theme", "accent", "locale", "container", "canonicalUrl", "slug"].forEach(function (key) {
      var attr = script.getAttribute("data-tb-" + key.replace(/[A-Z]/g, function (m) { return "-" + m.toLowerCase(); }));
      if (attr !== null) config[key] = attr;
    });
    return config;
  }

  function normalizeConfig(config) {
    var hasCustomAccent = Object.prototype.hasOwnProperty.call(config || {}, "accent") && String(config.accent || "") !== "";
    var next = merge(merge({}, DEFAULTS), config || {});
    next.endpoint = String(next.endpoint || "").replace(/\/+$/, "");
    next.site = String(next.site || next.siteId || "store-1");
    next.widgetType = ["feed", "post", "subscribe"].indexOf(next.widgetType) >= 0 ? next.widgetType : "feed";
    next.maxItems = Math.max(1, Math.min(50, parseInt(next.maxItems, 10) || 5));
    next.showExcerpt = next.showExcerpt === true || next.showExcerpt === "true" || next.showExcerpt === 1 || next.showExcerpt === "1";
    next.locale = String(next.locale || "en");
    next.accent = /^#[0-9a-f]{6}$/i.test(next.accent) ? next.accent : "#0a0a0a";
    next.hasCustomAccent = hasCustomAccent && /^#[0-9a-f]{6}$/i.test(next.accent);
    next.canonicalUrl = next.canonicalUrl || next.endpoint.replace(/\/api$/, "");
    return next;
  }

  function injectStyles() {
    if (document.getElementById("tinyblog-widget-style")) return;
    var style = document.createElement("style");
    style.id = "tinyblog-widget-style";
    style.textContent = [
      ".tbw{--tbw-paper:#f4f3ee;--tbw-panel:#faf9f5;--tbw-ink:#0a0a0a;--tbw-ink-soft:#2b2a27;--tbw-muted:#6c6a62;--tbw-line:#dcd9d0;--tbw-line-strong:#0a0a0a;--tbw-accent:#0a0a0a;--tbw-accent-soft:#eceaf9;--tbw-bg:var(--tbw-paper);--tbw-text:var(--tbw-ink);--tbw-soft:var(--tbw-panel);font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:var(--tbw-text);background:var(--tbw-bg);letter-spacing:0;max-width:760px}",
      ".tbw *{box-sizing:border-box}",
      ".tbw a{color:inherit;text-decoration-thickness:1px;text-underline-offset:3px}",
      ".tbw__frame{border:1px solid var(--tbw-line);background:var(--tbw-panel);padding:18px}",
      ".tbw__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}",
      ".tbw__title{font-size:15px;font-weight:800;margin:0}",
      ".tbw__list{display:grid;gap:0;margin:0;padding:0;list-style:none}",
      ".tbw__item{border-top:1px solid var(--tbw-line);padding:14px 0}",
      ".tbw__item:first-child{border-top:0;padding-top:0}",
      ".tbw__link{display:block;text-decoration:none}",
      ".tbw__post-title{font-size:clamp(20px,5vw,30px);line-height:1.08;font-weight:800;margin:0 0 7px}",
      ".tbw__meta,.tbw__excerpt,.tbw__fallback{font-size:14px;line-height:1.55;color:var(--tbw-muted);margin:0}",
      ".tbw__excerpt{color:var(--tbw-ink-soft);margin-top:8px}",
      ".tbw__image{width:100%;aspect-ratio:16/9;object-fit:cover;border:1px solid var(--tbw-line);margin:0 0 12px}",
      ".tbw__content{font-size:16px;line-height:1.72}",
      ".tbw__content p,.tbw__content ul,.tbw__content ol,.tbw__content blockquote{margin:0 0 1em}",
      ".tbw__content blockquote{border-left:2px solid var(--tbw-text);padding-left:12px;color:var(--tbw-ink-soft)}",
      ".tbw__content code{background:var(--tbw-soft);border:1px solid var(--tbw-line);padding:2px 5px}",
      ".tbw__form{display:grid;gap:10px}",
      ".tbw__label{font-size:13px;font-weight:700}",
      ".tbw__input{width:100%;border:1px solid var(--tbw-line);padding:11px 12px;background:var(--tbw-panel);color:var(--tbw-text);font:inherit}",
      ".tbw__button{border:1px solid var(--tbw-text);background:var(--tbw-text);color:var(--tbw-bg);padding:11px 13px;font:inherit;font-size:14px;font-weight:750;cursor:pointer}",
      ".tbw__button:focus,.tbw a:focus,.tbw__input:focus{outline:2px solid var(--tbw-accent);outline-offset:2px}",
      ".tbw[data-theme='dark']{--tbw-paper:#131210;--tbw-panel:#1a1917;--tbw-ink:#f2f1ec;--tbw-ink-soft:#d7d5cd;--tbw-muted:#9d9a90;--tbw-line:#2c2a26;--tbw-line-strong:#f2f1ec;--tbw-accent:#9aa6ff;--tbw-accent-soft:#1e2033;--tbw-bg:var(--tbw-paper);--tbw-text:var(--tbw-ink);--tbw-soft:var(--tbw-panel)}"
    ].join("");
    document.head.appendChild(style);
  }

  function resolveContainer(config, script) {
    var container = config.container ? document.querySelector(config.container) : null;
    if (container) return container;
    container = document.createElement("div");
    if (script && script.parentNode) {
      script.parentNode.insertBefore(container, script.nextSibling);
    } else {
      document.body.appendChild(container);
    }
    return container;
  }

  function apiUrl(config, path, params) {
    var query = merge({ site: config.site }, params || {});
    var url = new URL(config.endpoint + path, window.location.href);
    Object.keys(query).forEach(function (key) {
      if (query[key] !== undefined && query[key] !== "") {
        url.searchParams.set(key, query[key]);
      }
    });
    if (config.siteKey) {
      url.searchParams.set("siteKey", config.siteKey);
    }
    return url.toString();
  }

  function request(config, path, params, options) {
    if (!config.endpoint) {
      return Promise.reject(new Error("TinyBlog endpoint is required."));
    }
    var headers = { Accept: "application/json" };
    if (config.siteKey) headers["X-TinyBlog-Site-Key"] = config.siteKey;
    if (options && options.body) headers["Content-Type"] = "application/json";
    return fetch(apiUrl(config, path, params), merge({ headers: headers }, options || {})).then(function (response) {
      if (!response.ok) {
        throw new Error("TinyBlog request failed with status " + response.status);
      }
      return response.json();
    });
  }

  function root(config, container) {
    injectStyles();
    container.innerHTML = "";
    var frame = document.createElement("section");
    frame.className = "tbw";
    frame.dataset.theme = config.theme === "dark" ? "dark" : "light";
    if (config.hasCustomAccent) frame.style.setProperty("--tbw-accent", config.accent);
    frame.setAttribute("aria-label", "TinyBlog Widget");
    container.appendChild(frame);
    return frame;
  }

  function renderFallback(config, frame, message) {
    frame.innerHTML = '<div class="tbw__frame" role="status"><p class="tbw__fallback">' + escapeHtml(message || "TinyBlog could not load right now.") + ' <a href="' + escapeHtml(safeUrl(config.canonicalUrl) || "#") + '">Open blog</a>.</p></div>';
  }

  function formatMeta(post, locale) {
    var parts = [];
    var date = formatDate(post.published_at || post.publish_at, locale);
    if (date) parts.push(date);
    if (post.reading_minutes && Number(post.reading_minutes) > 0) {
      parts.push("~" + Number(post.reading_minutes) + " min read");
    }
    return parts.join(" - ");
  }

  function renderFeed(instance) {
    var config = instance.config;
    var frame = root(config, instance.container);
    frame.innerHTML = '<div class="tbw__frame" aria-busy="true"><p class="tbw__fallback">Loading posts...</p></div>';
    return request(config, "/posts", { limit: config.maxItems }).then(function (data) {
      var posts = Array.isArray(data.posts) ? data.posts : [];
      var html = '<div class="tbw__frame"><div class="tbw__head"><h2 class="tbw__title">' + escapeHtml(data.title || "Latest posts") + '</h2><a class="tbw__meta" href="' + escapeHtml(safeUrl(config.canonicalUrl) || "#") + '">View all</a></div>';
      if (!posts.length) {
        frame.innerHTML = html + '<p class="tbw__fallback" role="status">No posts yet.</p></div>';
        emit("loaded", { type: "feed", posts: posts, instance: instance });
        return;
      }
      html += '<ul class="tbw__list" role="list">';
      posts.forEach(function (post) {
        html += '<li class="tbw__item" role="listitem">';
        html += '<a class="tbw__link" href="' + escapeHtml(safeUrl(post.canonical_url) || "#") + '" data-tbw-slug="' + escapeHtml(post.slug) + '">';
        if (post.hero_image_url) html += '<img class="tbw__image" src="' + escapeHtml(safeUrl(post.hero_image_url)) + '" alt="" loading="lazy">';
        html += '<h3 class="tbw__post-title">' + escapeHtml(post.title) + '</h3>';
        html += '<p class="tbw__meta">' + escapeHtml(formatMeta(post, config.locale)) + '</p>';
        if (config.showExcerpt) html += '<p class="tbw__excerpt">' + escapeHtml(post.excerpt) + '</p>';
        html += '</a></li>';
      });
      html += '</ul></div>';
      frame.innerHTML = html;
      Array.prototype.slice.call(frame.querySelectorAll("[data-tbw-slug]")).forEach(function (link) {
        link.addEventListener("click", function (event) {
          if (config.inlinePost === true || config.inlinePost === "true") {
            event.preventDefault();
            instance.openPost(link.getAttribute("data-tbw-slug"));
          }
        });
      });
      emit("loaded", { type: "feed", posts: posts, instance: instance });
    }).catch(function (error) {
      renderFallback(config, frame, "Couldn't load posts.");
      emit("error", { error: error, instance: instance });
    });
  }

  function renderPost(instance, slug) {
    var config = instance.config;
    var frame = root(config, instance.container);
    var postSlug = slug || config.slug || config.postSlug || "";
    if (!postSlug) {
      renderFallback(config, frame, "TinyBlog post slug is missing.");
      return Promise.resolve();
    }
    frame.innerHTML = '<div class="tbw__frame" aria-busy="true"><p class="tbw__fallback">Loading post...</p></div>';
    return request(config, "/posts/" + encodeURIComponent(postSlug), {}).then(function (data) {
      var post = data.post || {};
      var html = '<article class="tbw__frame">';
      if (post.hero_image_url) html += '<img class="tbw__image" src="' + escapeHtml(safeUrl(post.hero_image_url)) + '" alt="" loading="lazy">';
      html += '<h2 class="tbw__post-title">' + escapeHtml(post.title) + '</h2>';
      html += '<p class="tbw__meta">' + escapeHtml(formatMeta(post, config.locale)) + '</p>';
      html += '<div class="tbw__content">' + sanitizeHtml(post.content_html || "") + '</div>';
      html += '<p class="tbw__meta"><a href="' + escapeHtml(safeUrl(post.canonical_url) || safeUrl(config.canonicalUrl) || "#") + '">Open canonical post</a></p>';
      html += '</article>';
      frame.innerHTML = html;
      emit("loaded", { type: "post", post: post, instance: instance });
    }).catch(function (error) {
      renderFallback(config, frame, "TinyBlog post could not load.");
      emit("error", { error: error, instance: instance });
    });
  }

  function renderSubscribe(instance) {
    var config = instance.config;
    var frame = root(config, instance.container);
    frame.innerHTML = '<div class="tbw__frame"><div class="tbw__head"><h2 class="tbw__title">Subscribe</h2></div><form class="tbw__form"><label class="tbw__label" for="tbw-email">Email</label><input class="tbw__input" id="tbw-email" type="email" autocomplete="email" required placeholder="you@example.com"><button class="tbw__button" type="submit">Subscribe</button><p class="tbw__fallback" role="status"></p></form></div>';
    var form = frame.querySelector("form");
    var status = frame.querySelector("[role='status']");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var email = frame.querySelector("#tbw-email").value;
      status.textContent = "Saving...";
      request(config, "/subscribe", {}, {
        method: "POST",
        body: JSON.stringify({ site: config.site, email: email })
      }).then(function (data) {
        status.textContent = data.message || "Subscribed.";
        form.reset();
        emit("loaded", { type: "subscribe", instance: instance });
      }).catch(function (error) {
        status.textContent = "Subscription failed. Open the blog and try again.";
        emit("error", { error: error, instance: instance });
      });
    });
  }

  function formatDate(value, locale) {
    if (!value) return "";
    try {
      return new Intl.DateTimeFormat(locale || "en", { year: "numeric", month: "short", day: "numeric" }).format(new Date(String(value).replace(" ", "T") + "Z"));
    } catch (error) {
      return escapeHtml(value);
    }
  }

  function init(config) {
    var normalized = normalizeConfig(config);
    var instance = {
      config: normalized,
      container: resolveContainer(normalized, currentScript),
      openPost: function (slug) {
        return renderPost(instance, slug);
      },
      refresh: function () {
        if (normalized.widgetType === "post") return renderPost(instance);
        if (normalized.widgetType === "subscribe") return renderSubscribe(instance);
        return renderFeed(instance);
      }
    };
    instances.push(instance);
    instance.refresh();
    return instance;
  }

  var TinyBlogWidget = {
    init: init,
    on: on,
    openPost: function (slug) {
      if (!instances.length) return Promise.reject(new Error("TinyBlogWidget has not been initialized."));
      return instances[0].openPost(slug);
    },
    escapeHtml: escapeHtml,
    sanitizeHtml: sanitizeHtml
  };

  window.TinyBlogWidget = TinyBlogWidget;

  if (currentScript && currentScript.getAttribute("data-tb-config")) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", function () {
        init(parseScriptConfig(currentScript));
      });
    } else {
      init(parseScriptConfig(currentScript));
    }
  }
})();

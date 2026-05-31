(function () {
  const path = window.location.pathname || "";
  if (/^\/bitrix\/admin(?:\/|$)/i.test(path) || /^\/bitrix\/tools(?:\/|$)/i.test(path)) {
    document.querySelectorAll(".draxter-aichat-root").forEach((el) => el.remove());
    return;
  }

  const DEFAULT_WELCOME =
    "Здравствуйте! Помогу подобрать товар, сравнить модели и ответить по ценам и наличию. Чем могу помочь?";
  const SESSION_KEY = "draxter_chat_session_id";
  const CLOSE_MS = 280;

  function uid() {
    return Date.now() + "-" + Math.random().toString(36).slice(2, 8);
  }

  function getSessionId() {
    try {
      const existing = sessionStorage.getItem(SESSION_KEY);
      if (existing) return existing;
      const id = "s_" + Date.now() + "_" + Math.random().toString(36).slice(2, 10);
      sessionStorage.setItem(SESSION_KEY, id);
      return id;
    } catch {
      return "s_" + Date.now() + "_" + Math.random().toString(36).slice(2, 10);
    }
  }

  function saveSessionId(id) {
    try {
      sessionStorage.setItem(SESSION_KEY, id);
    } catch {}
  }

  function getCookie(name) {
    const m = document.cookie.match(new RegExp("(?:^|; )" + name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "=([^;]*)"));
    return m ? decodeURIComponent(m[1]) : "";
  }

  function collectTracking() {
    const params = new URLSearchParams(window.location.search);
    const keys = ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content"];
    const tracking = {
      http_referer: document.referrer || "",
      page_url: window.location.href || "",
      page_title: document.title || "",
    };
    keys.forEach((k) => {
      tracking[k] = params.get(k) || getCookie(k) || "";
    });
    tracking._ym_uid = params.get("_ym_uid") || getCookie("_ym_uid") || "";
    return tracking;
  }

  function renderMarkdown(text) {
    let html = escapeHtml(text);
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, label, url) => {
      const href = (url || "").trim();
      if (!/^https?:\/\//i.test(href)) {
        return "[" + label + "](" + url + ")";
      }
      return (
        '<a href="' +
        href.replace(/"/g, "&quot;") +
        '" target="_blank" rel="noreferrer noopener">' +
        label +
        "</a>"
      );
    });
    html = html.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
    html = html.replace(/\*([^*]+)\*/g, '<em class="drax-em">$1</em>');
    html = html.replace(/\n/g, "<br>");
    return html;
  }

  function escapeHtml(s) {
    return s
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  const FAB_COLLAPSE_SVG =
    '<svg class="draxter-aichat-fab-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
    '<path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  const MIC_SVG =
    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
    '<path d="M12 14a3 3 0 003-3V5a3 3 0 10-6 0v6a3 3 0 003 3z" stroke="currentColor" stroke-width="2"/>' +
    '<path d="M19 11v1a7 7 0 01-14 0v-1M12 18v3M8 21h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

  function initRoot(root) {
    if (root.parentElement !== document.body) {
      document.body.appendChild(root);
    }

    const shopName = root.dataset.shopName || "Консультант";
    const agentName = root.dataset.agentName || shopName;
    const fabTitle = root.dataset.fabTitle || "Чат";
    const fabSubtitle = root.dataset.fabSubtitle || "Спросить о товаре";
    const statusText = root.dataset.statusText || "Онлайн • отвечает сразу";
    const showOnline = root.dataset.showOnline !== "0";
    const welcomeText = root.dataset.welcome || DEFAULT_WELCOME;
    const ajaxUrl = root.dataset.ajaxUrl || "/local/ajax/draxter_aichat.php";
    const embedded = root.dataset.embedded === "1";
    const avatarUrl = (root.dataset.avatarUrl || "").trim();
    const voiceEnabled = root.dataset.voiceEnabled === "1";
    const voiceReply = root.dataset.voiceReply === "1";
    const voiceMaxSeconds = Math.max(5, parseInt(root.dataset.voiceMaxSeconds || "60", 10) || 60);
    const voiceTtsMaxChars = Math.max(100, parseInt(root.dataset.voiceTtsMaxChars || "1500", 10) || 1500);
    const ttsSpeechRate = Math.max(0.5, Math.min(2, parseFloat(String(root.dataset.voiceTtsRate || "1.25").replace(",", ".")) || 1.25));
    const voiceStt = (root.dataset.voiceStt || "gemini").toLowerCase();
    const useBrowserStt = voiceStt === "browser";
    const TTS_CHUNK_SIZE = 280;
    let ttsSpeakGen = 0;
    let activeTtsAudio = null;
    let ttsUserGestureFresh = false;
    let ttsUnlockAt = 0;
    let ttsAudioCtx = null;
    let ttsUnlockAudioEl = null;
    const TTS_GESTURE_WINDOW_MS = 120000;

    function isMobileTtsDevice() {
      const touch =
        "ontouchstart" in window || (navigator.maxTouchPoints && navigator.maxTouchPoints > 0);
      const narrow = window.matchMedia && window.matchMedia("(max-width: 768px)").matches;
      const mobileUa = /iPhone|iPad|iPod|Android|webOS|Mobile/i.test(navigator.userAgent || "");
      return !!(touch && (narrow || mobileUa));
    }

    function isIosSafari() {
      const ua = navigator.userAgent || "";
      return /iPhone|iPad|iPod/i.test(ua) && !/CriOS|FxiOS|OPiOS|EdgiOS/i.test(ua);
    }

    function isTtsGestureUnlocked() {
      return ttsUserGestureFresh || Date.now() - ttsUnlockAt < TTS_GESTURE_WINDOW_MS;
    }

    function ttsUserActivated(fromUserClick) {
      return !!fromUserClick || isTtsGestureUnlocked();
    }

    function consumeTtsGesture() {
      ttsUserGestureFresh = false;
    }

    function prepSpeechSynthesisForSpeak() {
      if (!window.speechSynthesis) return;
      try {
        if (speechSynthesis.paused) speechSynthesis.resume();
        if (isIosSafari()) {
          const silent = new SpeechSynthesisUtterance("\u200b");
          silent.volume = 0;
          silent.rate = 10;
          speechSynthesis.speak(silent);
          speechSynthesis.cancel();
          speechSynthesis.resume();
        }
      } catch (_) {}
    }

    function unlockTtsPlayback() {
      ttsUserGestureFresh = true;
      ttsUnlockAt = Date.now();
      try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (AC) {
          if (!ttsAudioCtx) ttsAudioCtx = new AC();
          const ctx = ttsAudioCtx;
          const resume = ctx.state === "suspended" ? ctx.resume() : Promise.resolve();
          resume.then(function () {
            try {
              const buf = ctx.createBuffer(1, 1, ctx.sampleRate || 22050);
              const src = ctx.createBufferSource();
              src.buffer = buf;
              src.connect(ctx.destination);
              src.start(0);
            } catch (_) {}
          });
        }
      } catch (_) {}
      try {
        if (!ttsUnlockAudioEl) {
          ttsUnlockAudioEl = document.createElement("audio");
          ttsUnlockAudioEl.setAttribute("playsinline", "");
          ttsUnlockAudioEl.preload = "auto";
          ttsUnlockAudioEl.style.cssText =
            "position:fixed;width:0;height:0;opacity:0;pointer-events:none";
          document.body.appendChild(ttsUnlockAudioEl);
        }
        ttsUnlockAudioEl.src =
          "data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA";
        const unlockPlay = ttsUnlockAudioEl.play();
        if (unlockPlay && unlockPlay.catch) unlockPlay.catch(function () {});
      } catch (_) {}
      prepSpeechSynthesisForSpeak();
    }

    function markTtsUserGesture() {
      unlockTtsPlayback();
    }

    const ymEnabled = root.dataset.ymEnabled === "1";
    const ymCounter = root.dataset.ymCounter || "";
    const ymGoal = root.dataset.ymGoal || "aibot";
    const LEAD_MARKER = "<!--draxter-aichat-lead:1-->";

    function fireMetrikaLeadGoal() {
      if (!ymEnabled) return;
      const counter = parseInt(ymCounter, 10);
      const goal = (ymGoal || "").trim();
      if (!counter || !goal) return;
      try {
        if (typeof ym === "function") {
          ym(counter, "reachGoal", goal);
        }
      } catch (_) {}
    }

    function processLeadCreatedSignal(headerLead, text) {
      let leadCreated = headerLead;
      let out = text || "";
      if (out.includes(LEAD_MARKER)) {
        leadCreated = true;
        out = out.replace(LEAD_MARKER, "").trim();
      }
      if (leadCreated) {
        fireMetrikaLeadGoal();
      }
      return out;
    }

    function avatarHtml(extraClass) {
      const cls = "draxter-aichat-avatar" + (extraClass ? " " + extraClass : "");
      if (avatarUrl) {
        return '<img class="' + cls + '" src="' + escapeHtml(avatarUrl) + '" alt="" loading="lazy">';
      }
      return '<div class="' + cls + ' draxter-aichat-avatar--empty"></div>';
    }

    function fabClosedHtml() {
      return (
        avatarHtml("draxter-aichat-fab-avatar") +
        '<span class="draxter-aichat-fab-label"><strong>' +
        escapeHtml(fabTitle) +
        "</strong>" +
        '<span class="draxter-aichat-fab-sublabel">' +
        escapeHtml(fabSubtitle) +
        "</span></span>"
      );
    }

    let panelOpen = embedded;
    let panelMounted = embedded;
    let closing = false;
    let loading = false;
    let recording = false;
    let messages = [{ id: "welcome", role: "assistant", content: welcomeText }];
    let error = null;

    function userFacingError(message, code) {
      const byCode = {
        QUOTA_EXCEEDED:
          "Сейчас консультант перегружен. Подождите минуту и попробуйте снова, или напишите текстом.",
        API_DISABLED: "Консультант временно недоступен. Попробуйте позже.",
        INVALID_KEY: "Консультант временно недоступен. Попробуйте позже.",
        STT_ERROR:
          "Не удалось распознать речь. Попробуйте ещё раз или введите сообщение текстом.",
        TTS_ERROR: "Озвучка временно недоступна.",
        LLM_API_ERROR:
          "Сейчас консультант перегружен. Подождите минуту и попробуйте снова, или напишите текстом.",
      };
      if (code && byCode[code]) return byCode[code];
      const msg = String(message || "");
      if (
        /лимит|квот|gemini|429|quota|исчерпан|aistudio|apikey|rate.?limit|google ai/i.test(
          msg
        )
      ) {
        return byCode.QUOTA_EXCEEDED;
      }
      if (/stt|распозна|transcri/i.test(msg)) return byCode.STT_ERROR;
      if (/tts|озвуч|http error/i.test(msg)) return byCode.TTS_ERROR;
      if (/api.?ключ|invalid.*key|настройках модуля/i.test(msg)) {
        return byCode.INVALID_KEY;
      }
      if (
        msg.indexOf("Пустой ответ") >= 0 ||
        msg.indexOf("Ответ слишком") >= 0 ||
        msg.indexOf("не успел ответить") >= 0
      ) {
        return msg;
      }
      return byCode.ERROR || "Не удалось получить ответ. Попробуйте ещё раз.";
    }

    let fab = null;
    let panel = null;
    let listEl = null;
    let inputEl = null;
    let sendBtn = null;
    let micBtn = null;
    let mediaRecorder = null;
    let recordChunks = [];
    let recordMime = "audio/webm";
    let voicePhase = "idle";
    let voiceStatusEl = null;
    let recordStartedAt = 0;
    let speechRecognition = null;
    let browserSttParts = [];
    let browserSttInterim = "";
    let browserSttLastError = "";
    let browserSttStopTimer = null;
    let browserMicStream = null;
    let browserSttRestartTimer = null;
    let browserSttRestartCount = 0;
    let browserSttLangIndex = 0;
    let browserSttStopping = false;
    let browserSttEmptyResultEvents = 0;
    const browserSttLangs = ["ru-RU", "ru"];

    function isEdgeBrowser() {
      const ua = navigator.userAgent || "";
      return /Edg\//.test(ua);
    }

    function getSpeechRecognitionCtor() {
      return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    function releaseBrowserMic() {
      if (browserMicStream) {
        browserMicStream.getTracks().forEach(function (t) {
          t.stop();
        });
        browserMicStream = null;
      }
    }

    function buildBrowserSttText() {
      const finalText = browserSttParts.join(" ").trim();
      if (finalText) return finalText;
      return (browserSttInterim || "").trim();
    }

    function browserSttEmptyError() {
      const err = browserSttLastError;
      if (err === "not-allowed") {
        return "Нет доступа к микрофону. Разрешите доступ в настройках браузера.";
      }
      if (err === "edge-empty-results") {
        return (
          "Edge: распознавание не вернуло текст (служба Microsoft). " +
          "Включите «Распознавание речи в сети» (edge://settings/languages), перезапустите браузер. " +
          "Режим «Браузер» стабилен в Chrome; в Edge часто недоступен из‑за ограничений Microsoft."
        );
      }
      if (err === "network") {
        if (isEdgeBrowser()) {
          return (
            "Edge: не удалось подключиться к службе распознавания речи Microsoft. " +
            "Если раньше микрофон работал — скорее всего был режим «Авто» или «Только Gemini» (голос через Google, не через браузер). " +
            "Переключите STT на вкладке «Голос» модуля. Либо включите «Распознавание речи в сети» (edge://settings/languages)."
          );
        }
        return "Нет связи с сервисом распознавания (нужен интернет). Проверьте подключение и попробуйте снова.";
      }
      if (err === "language-not-supported") {
        return "Браузер не поддерживает русский язык для распознавания. Обновите браузер или выберите режим «Только Gemini» на вкладке «Голос».";
      }
      if (err === "audio-capture") {
        return "Микрофон недоступен. Проверьте подключение и разрешения браузера.";
      }
      if (err === "service-not-allowed") {
        return "Распознавание речи заблокировано браузером (нужен HTTPS).";
      }
      if (err === "no-speech" || err === "no-match") {
        return "Речь не распознана. Проверьте микрофон и говорите чётче, затем отпустите кнопку.";
      }
      if (err) {
        return "Не удалось распознать речь (" + err + "). Проверьте микрофон и вкладку «Голос».";
      }
      return "Не удалось распознать речь. Проверьте микрофон и настройки STT на вкладке «Голос» модуля.";
    }

    function applyBrowserSttResults(rec) {
      if (!rec || !rec.results) return;
      const finals = [];
      let interim = "";
      for (let i = 0; i < rec.results.length; i++) {
        const part = (rec.results[i][0] && rec.results[i][0].transcript) || "";
        const t = part.trim();
        if (!t) continue;
        if (rec.results[i].isFinal) finals.push(t);
        else interim = t;
      }
      browserSttParts = finals;
      browserSttInterim = interim;
    }

    function ingestBrowserSttResult(rec) {
      applyBrowserSttResults(rec);
      const text = buildBrowserSttText();
      if (text) {
        browserSttLastError = "";
        return text;
      }
      if (rec && rec.results) {
        browserSttEmptyResultEvents++;
      }
      return "";
    }

    function finalizeBrowserSttEmptyError() {
      if (browserSttLastError) return;
      if (!isEdgeBrowser()) return;
      if (browserSttEmptyResultEvents > 0) {
        browserSttLastError = "edge-empty-results";
      }
    }

    function clearBrowserSttRestartTimer() {
      if (browserSttRestartTimer) {
        clearTimeout(browserSttRestartTimer);
        browserSttRestartTimer = null;
      }
    }

    function abortBrowserSttRec() {
      if (!speechRecognition) return;
      try {
        speechRecognition.onend = null;
        speechRecognition.onerror = null;
        speechRecognition.onresult = null;
        speechRecognition.abort();
      } catch {}
      speechRecognition = null;
    }

    function scheduleBrowserSttContinue(rec, delayMs, forceRespawn) {
      clearBrowserSttRestartTimer();
      if (!recording) return;
      if (browserSttRestartCount >= 8) return;
      browserSttRestartTimer = setTimeout(function () {
        browserSttRestartTimer = null;
        if (!recording) return;
        if (browserSttLastError === "network" || browserSttLastError === "language-not-supported") {
          return;
        }
        browserSttRestartCount++;
        if (forceRespawn || !rec || speechRecognition !== rec) {
          spawnBrowserSttRec(false);
          return;
        }
        try {
          rec.start();
        } catch {
          spawnBrowserSttRec(false);
        }
      }, delayMs);
    }

    function bindBrowserSttHandlers(rec) {
      rec.onresult = function () {
        ingestBrowserSttResult(rec);
      };
      rec.onerror = function (e) {
        const code = e && e.error ? String(e.error) : "unknown";
        if (code === "aborted") return;
        browserSttLastError = code;
        if (code === "language-not-supported" && browserSttLangIndex + 1 < browserSttLangs.length) {
          browserSttLangIndex++;
          spawnBrowserSttRec(false);
        }
      };
      rec.onnomatch = function () {
        if (!browserSttLastError) browserSttLastError = "no-match";
      };
      rec.onend = function () {
        if (!recording || speechRecognition !== rec) return;
        if (browserSttLastError === "network" || browserSttLastError === "language-not-supported") {
          return;
        }
        scheduleBrowserSttContinue(rec, 300, false);
      };
    }

    function spawnBrowserSttRec(resetLang) {
      const Ctor = getSpeechRecognitionCtor();
      if (!Ctor) return false;
      if (resetLang !== false) {
        browserSttLangIndex = 0;
      }
      abortBrowserSttRec();
      const rec = new Ctor();
      speechRecognition = rec;
      rec.lang = browserSttLangs[browserSttLangIndex] || "ru-RU";
      rec.continuous = true;
      rec.interimResults = true;
      rec.maxAlternatives = 1;
      bindBrowserSttHandlers(rec);
      try {
        rec.start();
        return true;
      } catch (err) {
        speechRecognition = null;
        if (browserSttRestartCount < 3) {
          scheduleBrowserSttContinue(null, 600, true);
          return true;
        }
        return false;
      }
    }

    function startBrowserStt() {
      browserSttParts = [];
      browserSttInterim = "";
      browserSttLastError = "";
      browserSttEmptyResultEvents = 0;
      browserSttRestartCount = 0;
      browserSttLangIndex = 0;
      clearBrowserSttRestartTimer();
      return spawnBrowserSttRec(true);
    }

    function stopBrowserStt() {
      return new Promise(function (resolve) {
        clearBrowserSttRestartTimer();
        browserSttStopping = true;
        if (!speechRecognition) {
          browserSttStopping = false;
          resolve(buildBrowserSttText());
          return;
        }
        const rec = speechRecognition;
        speechRecognition = null;
        let done = false;
        const finish = function () {
          if (done) return;
          done = true;
          browserSttStopping = false;
          ingestBrowserSttResult(rec);
          const text = buildBrowserSttText();
          if (text) browserSttLastError = "";
          else finalizeBrowserSttEmptyError();
          resolve(text);
        };
        rec.onresult = function () {
          const text = ingestBrowserSttResult(rec);
          if (text) {
            setTimeout(finish, 120);
          }
        };
        rec.onerror = function (e) {
          const code = e && e.error ? String(e.error) : "unknown";
          if (code !== "aborted") browserSttLastError = code;
        };
        rec.onend = function () {
          setTimeout(finish, isEdgeBrowser() ? 600 : 300);
        };
        try {
          rec.stop();
        } catch {
          try {
            rec.abort();
          } catch {}
          finish();
          return;
        }
        setTimeout(finish, isEdgeBrowser() ? 4500 : 3500);
      });
    }

    let browserMicWarmed = false;

    function warmBrowserMicOnce() {
      if (browserMicWarmed || !useBrowserStt || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return;
      }
      browserMicWarmed = true;
      navigator.mediaDevices
        .getUserMedia({ audio: true })
        .then(function (stream) {
          stream.getTracks().forEach(function (t) {
            t.stop();
          });
        })
        .catch(function () {});
    }

    function beginBrowserRecordingUi() {
      recording = true;
      voicePhase = "recording";
      recordStartedAt = Date.now();
      const maxMs = voiceMaxSeconds * 1000;
      browserSttStopTimer = setTimeout(function () {
        stopBrowserRecording();
      }, maxMs);
      setVoiceStatus("Говорите… Нажмите микрофон ещё раз, чтобы отправить");
      if (micBtn) {
        micBtn.classList.add("draxter-aichat-mic--active");
        micBtn.setAttribute("aria-label", "Остановить запись и отправить");
      }
      updateSendButton();
    }

    async function startBrowserRecordingFromGesture() {
      if (loading || recording) return;
      if (!voiceEnabled) {
        error =
          "Голосовой ввод отключён. Включите «Голосовой ввод» в настройках модуля (вкладка «Голос»).";
        renderMessages();
        return;
      }
      if (!getSpeechRecognitionCtor()) {
        error =
          "Браузер не поддерживает распознавание речи. Используйте Chrome по HTTPS, либо режим «Только Gemini» на вкладке «Голос».";
        renderMessages();
        return;
      }
      error = null;
      if (!startBrowserStt()) {
        error = "Не удалось запустить распознавание в браузере";
        renderMessages();
        return;
      }
      beginBrowserRecordingUi();
    }

    function setVoiceStatus(text) {
      if (!voiceStatusEl) return;
      if (text) {
        voiceStatusEl.hidden = false;
        voiceStatusEl.textContent = text;
      } else {
        voiceStatusEl.hidden = true;
        voiceStatusEl.textContent = "";
      }
    }

    function pickRecordMime() {
      const types = ["audio/webm;codecs=opus", "audio/webm", "audio/mp4", "audio/ogg"];
      for (let i = 0; i < types.length; i++) {
        if (MediaRecorder.isTypeSupported(types[i])) return types[i];
      }
      return "";
    }

    function setFabState(isOpen) {
      if (!fab) return;
      if (isOpen) {
        fab.classList.add("draxter-aichat-fab--open");
        fab.innerHTML = FAB_COLLAPSE_SVG;
        fab.setAttribute("aria-label", "Свернуть чат");
        fab.setAttribute("aria-expanded", "true");
      } else {
        fab.classList.remove("draxter-aichat-fab--open");
        fab.innerHTML = fabClosedHtml();
        fab.setAttribute("aria-label", "Открыть чат с консультантом");
        fab.setAttribute("aria-expanded", "false");
      }
    }

    function updateSendButton() {
      if (!sendBtn || !inputEl) return;
      sendBtn.disabled = loading || recording || !(inputEl.value || "").trim();
      if (micBtn) micBtn.disabled = loading;
    }

    function scrollBottom() {
      if (listEl) listEl.scrollTop = listEl.scrollHeight;
    }

    function renderMessages() {
      if (!listEl) return;
      listEl.innerHTML = "";
      const lastMsg = messages[messages.length - 1];
      const showTyping =
        loading &&
        (voicePhase === "transcribing" ||
          !lastMsg ||
          lastMsg.role === "user" ||
          (lastMsg.role === "assistant" && !lastMsg.content && !lastMsg.audioUrl));

      messages.forEach((m) => {
        if (m.role === "assistant") {
          if (!m.content && !m.audioUrl && showTyping) return;
          const row = document.createElement("div");
          row.className = "draxter-aichat-msg";
          let inner = avatarHtml() + '<div class="draxter-aichat-bubble draxter-aichat-bubble--bot">';
          if (m.content) inner += renderMarkdown(m.content);
          if (m.audioUrl) {
            inner +=
              '<div class="draxter-aichat-audio-wrap"><audio controls preload="none" playsinline src="' +
              escapeHtml(m.audioUrl) +
              '"></audio></div>';
          }
          if (m.ttsListen) {
            inner +=
              '<button type="button" class="draxter-aichat-tts-btn' +
              (m.ttsListenHighlight ? " draxter-aichat-tts-btn--highlight" : "") +
              '" data-msg-id="' +
              escapeHtml(m.id) +
              '">Прослушать</button>';
          }
          inner += "</div>";
          row.innerHTML = inner;
          listEl.appendChild(row);
        } else {
          const row = document.createElement("div");
          row.className = "draxter-aichat-msg draxter-aichat-msg--user";
          row.innerHTML =
            '<div class="draxter-aichat-bubble draxter-aichat-bubble--user">' +
            escapeHtml(m.content) +
            "</div>";
          listEl.appendChild(row);
        }
      });

      if (showTyping) {
        const t = document.createElement("div");
        t.className = "draxter-aichat-msg";
        t.innerHTML =
          avatarHtml() +
          '<div class="draxter-aichat-bubble draxter-aichat-bubble--bot draxter-aichat-typing"><span></span><span></span><span></span></div>';
        listEl.appendChild(t);
      }

      if (error) {
        const e = document.createElement("p");
        e.className = "draxter-aichat-error";
        e.textContent = error;
        listEl.appendChild(e);
      }

      scrollBottom();
    }

    /** Keep in sync with TtsService::normalizeTextForTts() */
    function pluralRu(n, one, few, many) {
      const mod10 = n % 10;
      const mod100 = n % 100;
      if (mod10 === 1 && mod100 !== 11) return one;
      if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few;
      return many;
    }

    function formatRussianNumberSpeakable(n) {
      n = Math.round(n);
      if (!Number.isFinite(n) || n < 0) return String(n);
      if (n >= 1000000) {
        const millions = Math.floor(n / 1000000);
        let s = millions + " " + pluralRu(millions, "миллион", "миллиона", "миллионов");
        n = n % 1000000;
        if (n >= 1000) {
          const thousands = Math.floor(n / 1000);
          s += " " + thousands + " " + pluralRu(thousands, "тысяча", "тысячи", "тысяч");
          n = n % 1000;
        }
        if (n > 0) s += " " + n;
        return s;
      }
      if (n >= 1000) {
        const thousands = Math.floor(n / 1000);
        let s = thousands + " " + pluralRu(thousands, "тысяча", "тысячи", "тысяч");
        n = n % 1000;
        if (n > 0) s += " " + n;
        return s;
      }
      return String(n);
    }

    function normalizeTextForTts(text) {
      let s = text || "";
      s = s.replace(/(\d[\d\s\u00a0]*)\s*(?:₽|руб\.?|RUB|rub)(?:[\s.,;]|$)/gi, function (_, num) {
        const n = parseInt(String(num).replace(/[\s\u00a0]+/g, ""), 10);
        if (isNaN(n)) return num + " рублей ";
        return formatRussianNumberSpeakable(n) + " рублей ";
      });
      s = s.replace(/\d{1,3}(?:[\s\u00a0]\d{3})+(?:[.,]\d+)?/g, function (m) {
        return m.replace(/[\s\u00a0]+/g, "");
      });
      s = s.replace(/(\d)\s*[\*×xX]\s*(\d)/g, "$1 на $2");
      s = s.replace(/[×]/g, " на ");
      s = s.replace(/[–—−]/g, "-");
      s = s.replace(/^\s*\|.*\|\s*$/gm, " ");
      s = s.replace(/\|/g, " ");
      s = s.replace(/^>\s+/gm, "");
      s = s.replace(/\*+/g, "");
      return s;
    }

    function plainTextForTts(text, maxLen) {
      const limit = maxLen || voiceTtsMaxChars;
      let s = (text || "")
        .replace(/<!--[\s\S]*?-->/g, "")
        .replace(/\[([^\]]+)\]\([^)]+\)/g, "$1")
        .replace(/\*\*([^*]+)\*\*/g, "$1")
        .replace(/\*([^*\n]+)\*/g, "$1")
        .replace(/`([^`]+)`/g, "$1")
        .replace(/^#{1,6}\s+/gm, "")
        .replace(/^[\-*•]\s+/gm, "");
      s = normalizeTextForTts(s);
      s = s
        .replace(/(?<!\w)\*(?!\w)/g, "")
        .replace(/<[^>]+>/g, "")
        .replace(/\s+/g, " ")
        .trim();
      if (s.length > limit) {
        s = s.slice(0, limit).trim() + "…";
      }
      return s;
    }

    function splitTtsChunks(text, maxLen) {
      const limit = maxLen || TTS_CHUNK_SIZE;
      const trimmed = (text || "").trim();
      if (!trimmed) return [];
      if (trimmed.length <= limit) return [trimmed];
      const parts = trimmed.split(/(?<=[.!?…])\s+/);
      const chunks = [];
      let buf = "";
      parts.forEach(function (part) {
        const piece = part.trim();
        if (!piece) return;
        const next = buf ? buf + " " + piece : piece;
        if (next.length <= limit) {
          buf = next;
          return;
        }
        if (buf) chunks.push(buf);
        if (piece.length <= limit) {
          buf = piece;
          return;
        }
        for (let i = 0; i < piece.length; i += limit) {
          chunks.push(piece.slice(i, i + limit));
        }
        buf = "";
      });
      if (buf) chunks.push(buf);
      return chunks.length ? chunks : [trimmed.slice(0, limit)];
    }

    function stopTtsPlayback() {
      ttsSpeakGen++;
      if (window.speechSynthesis) {
        speechSynthesis.cancel();
      }
      if (activeTtsAudio) {
        activeTtsAudio.pause();
        activeTtsAudio.currentTime = 0;
        activeTtsAudio = null;
      }
      if (listEl) {
        listEl.querySelectorAll("audio").forEach(function (a) {
          a.pause();
          a.currentTime = 0;
        });
      }
    }

    let voicesReady = null;
    function waitForVoices() {
      if (!window.speechSynthesis) return Promise.resolve([]);
      if (voicesReady) return voicesReady;
      voicesReady = new Promise(function (resolve) {
        const voices = speechSynthesis.getVoices();
        if (voices.length) {
          resolve(voices);
          return;
        }
        const onVoices = function () {
          speechSynthesis.removeEventListener("voiceschanged", onVoices);
          resolve(speechSynthesis.getVoices());
        };
        speechSynthesis.addEventListener("voiceschanged", onVoices);
        setTimeout(function () {
          speechSynthesis.removeEventListener("voiceschanged", onVoices);
          resolve(speechSynthesis.getVoices());
        }, 1500);
      });
      return voicesReady;
    }

    function pickRussianVoice(voices) {
      if (!voices || !voices.length) return null;
      const ru = voices.filter(function (v) {
        return /^ru/i.test(v.lang || "");
      });
      if (!ru.length) return null;
      const preferred = ["Google русский", "Microsoft Irina", "Milena", "Katya"];
      for (let i = 0; i < preferred.length; i++) {
        const match = ru.find(function (v) {
          return v.name.indexOf(preferred[i]) >= 0;
        });
        if (match) return match;
      }
      return ru[0];
    }

    function speakWithBrowserTts(plain, fromUserClick) {
      if (!window.speechSynthesis || !plain) return Promise.resolve(false);
      const activated = ttsUserActivated(fromUserClick);
      if (isMobileTtsDevice() && !activated) return Promise.resolve(false);
      const gen = ++ttsSpeakGen;
      const chunks = splitTtsChunks(plain, TTS_CHUNK_SIZE);
      if (!chunks.length) return Promise.resolve(false);
      return waitForVoices().then(function (voices) {
        const voice = pickRussianVoice(voices);
        return new Promise(function (resolve) {
          let idx = 0;
          let spokeAny = false;

          function speakNext() {
            if (gen !== ttsSpeakGen) {
              resolve(spokeAny);
              return;
            }
            if (idx >= chunks.length) {
              resolve(spokeAny);
              return;
            }
            const chunk = chunks[idx++];
            const u = new SpeechSynthesisUtterance(chunk);
            u.lang = "ru-RU";
            u.rate = ttsSpeechRate;
            if (voice) u.voice = voice;

            let finished = false;
            function advance() {
              if (finished) return;
              finished = true;
              spokeAny = true;
              prepSpeechSynthesisForSpeak();
              setTimeout(speakNext, 50);
            }

            u.onstart = function () {
              spokeAny = true;
            };
            u.onend = advance;
            u.onerror = function (ev) {
              if (gen !== ttsSpeakGen) {
                resolve(spokeAny);
                return;
              }
              const err = ev && ev.error ? String(ev.error) : "";
              if (err === "interrupted" || err === "canceled") {
                if (!spokeAny && idx < chunks.length) {
                  prepSpeechSynthesisForSpeak();
                  setTimeout(speakNext, 120);
                  return;
                }
                resolve(spokeAny);
                return;
              }
              advance();
            };
            prepSpeechSynthesisForSpeak();
            speechSynthesis.speak(u);
            setTimeout(function () {
              if (gen !== ttsSpeakGen || finished) return;
              if (window.speechSynthesis && speechSynthesis.paused) {
                try {
                  speechSynthesis.resume();
                } catch (_) {}
              }
              if (!spokeAny && speechSynthesis.speaking === false && idx <= 1) {
                prepSpeechSynthesisForSpeak();
                speechSynthesis.speak(u);
              }
            }, 150);
          }

          speechSynthesis.cancel();
          setTimeout(function () {
            prepSpeechSynthesisForSpeak();
            speakNext();
          }, activated && isIosSafari() ? 80 : 50);
        });
      });
    }

    function showTtsListenButton(assistantId, highlight) {
      const idx = messages.findIndex(function (m) {
        return m.id === assistantId;
      });
      if (idx < 0) return;
      messages[idx].ttsListen = true;
      if (highlight) messages[idx].ttsListenHighlight = true;
      renderMessages();
    }

    function playAudioElement(audio) {
      audio.playbackRate = ttsSpeechRate;
      audio.setAttribute("playsinline", "");
      activeTtsAudio = audio;
      return audio.play();
    }

    function playAudioUrlViaUnlock(url) {
      const audio = new Audio(url);
      audio.playbackRate = ttsSpeechRate;
      audio.setAttribute("playsinline", "");
      activeTtsAudio = audio;
      return audio.play().catch(function () {
        if (!ttsAudioCtx) return Promise.reject(new Error("play blocked"));
        const ctx = ttsAudioCtx;
        const resume = ctx.state === "suspended" ? ctx.resume() : Promise.resolve();
        return resume.then(function () {
          return fetch(url)
            .then(function (res) {
              return res.arrayBuffer();
            })
            .then(function (arr) {
              return ctx.decodeAudioData(arr.slice(0));
            })
            .then(function (buf) {
              return new Promise(function (resolve, reject) {
                const src = ctx.createBufferSource();
                src.buffer = buf;
                src.playbackRate.value = ttsSpeechRate;
                src.connect(ctx.destination);
                src.onended = function () {
                  if (activeTtsAudio === audio) activeTtsAudio = null;
                  resolve();
                };
                src.start(0);
              });
            });
        });
      });
    }

    function playMessageAudio(assistantId) {
      if (!listEl) return Promise.resolve(false);
      const btn = listEl.querySelector(
        '.draxter-aichat-tts-btn[data-msg-id="' + assistantId + '"]'
      );
      const row = btn ? btn.closest(".draxter-aichat-msg") : null;
      const audio = row ? row.querySelector("audio") : null;
      if (!audio) return Promise.resolve(false);
      return playAudioElement(audio).then(
        function () {
          consumeTtsGesture();
          const idx = messages.findIndex(function (m) {
            return m.id === assistantId;
          });
          if (idx >= 0) {
            messages[idx].ttsListen = false;
            messages[idx].ttsListenHighlight = false;
            renderMessages();
          }
          audio.addEventListener(
            "ended",
            function () {
              if (activeTtsAudio === audio) activeTtsAudio = null;
            },
            { once: true }
          );
          return true;
        },
        function () {
          const msg = messages.find(function (m) {
            return m.id === assistantId;
          });
          if (msg && msg.audioUrl) {
            return playAudioUrlViaUnlock(msg.audioUrl).then(
              function () {
                consumeTtsGesture();
                const idx = messages.findIndex(function (m) {
                  return m.id === assistantId;
                });
                if (idx >= 0) {
                  messages[idx].ttsListen = false;
                  messages[idx].ttsListenHighlight = false;
                  renderMessages();
                }
                return true;
              },
              function () {
                showTtsListenButton(assistantId, true);
                setVoiceStatus("Нажмите «Прослушать» ещё раз");
                setTimeout(function () {
                  setVoiceStatus("");
                }, 5000);
                return false;
              }
            );
          }
          showTtsListenButton(assistantId, true);
          setVoiceStatus("Нажмите «Прослушать» ещё раз");
          setTimeout(function () {
            setVoiceStatus("");
          }, 5000);
          return false;
        }
      );
    }

    async function prefetchTtsForMessage(assistantId, text) {
      const plain = plainTextForTts(text);
      if (!plain) return;
      try {
        const sep = ajaxUrl.indexOf("?") >= 0 ? "&" : "?";
        const res = await fetch(ajaxUrl + sep + "action=tts", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ text: text }),
        });
        const contentType = (res.headers.get("Content-Type") || "").toLowerCase();
        if (contentType.indexOf("application/json") >= 0) {
          const data = await res.json();
          if (data && data.mode === "browser" && data.text) {
            const idx = messages.findIndex(function (m) {
              return m.id === assistantId;
            });
            if (idx >= 0) {
              messages[idx].ttsMode = "browser";
              messages[idx].ttsText = plainTextForTts(data.text) || plain;
            }
            return;
          }
        }
        if (!res.ok) return;
        const blob = await res.blob();
        if (!blob.size) return;
        const url = URL.createObjectURL(blob);
        const idx = messages.findIndex(function (m) {
          return m.id === assistantId;
        });
        if (idx >= 0) {
          if (messages[idx].audioUrl) URL.revokeObjectURL(messages[idx].audioUrl);
          messages[idx].audioUrl = url;
          renderMessages();
        }
      } catch (_) {}
    }

    async function playTtsForMessage(assistantId, text, fromUserClick) {
      if (!voiceReply || !text.trim()) return;

      const activated = ttsUserActivated(fromUserClick);
      const msgIdx = messages.findIndex(function (m) {
        return m.id === assistantId;
      });
      if (msgIdx >= 0 && activated) {
        if (messages[msgIdx].audioUrl) {
          stopTtsPlayback();
          if (await playMessageAudio(assistantId)) return;
        }
        if (messages[msgIdx].ttsMode === "browser" && messages[msgIdx].ttsText) {
          stopTtsPlayback();
          if (await speakWithBrowserTts(messages[msgIdx].ttsText, true)) {
            consumeTtsGesture();
            messages[msgIdx].ttsListen = false;
            messages[msgIdx].ttsListenHighlight = false;
            renderMessages();
            return;
          }
        }
      }

      if (isMobileTtsDevice() && !activated) {
        showTtsListenButton(assistantId, true);
        return;
      }

      stopTtsPlayback();
      const plain = plainTextForTts(text);
      if (!plain) return;

      try {
        const sep = ajaxUrl.indexOf("?") >= 0 ? "&" : "?";
        const res = await fetch(ajaxUrl + sep + "action=tts", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ text: text }),
        });
        const contentType = (res.headers.get("Content-Type") || "").toLowerCase();
        let data = null;
        if (contentType.indexOf("application/json") >= 0) {
          data = await res.json();
        }

        if (data) {
          if (!res.ok) {
            const msg = userFacingError(data.error, data.code) || "Озвучка временно недоступна.";
            if (await speakWithBrowserTts(plain, activated)) {
              consumeTtsGesture();
              const idx = messages.findIndex(function (m) {
                return m.id === assistantId;
              });
              if (idx >= 0) {
                messages[idx].ttsListen = false;
                messages[idx].ttsListenHighlight = false;
              }
              setVoiceStatus("Озвучка браузера");
              setTimeout(function () {
                setVoiceStatus("");
              }, 4000);
              return;
            }
            showTtsListenButton(assistantId, true);
            if (!fromUserClick) {
              setVoiceStatus(msg);
              setTimeout(function () {
                setVoiceStatus("");
              }, 6000);
            }
            return;
          }
          if (data.mode === "browser" && data.text) {
            const ttsText = plainTextForTts(data.text) || plain;
            if (await speakWithBrowserTts(ttsText, activated)) {
              consumeTtsGesture();
              const idx = messages.findIndex(function (m) {
                return m.id === assistantId;
              });
              if (idx >= 0) {
                messages[idx].ttsListen = false;
                messages[idx].ttsListenHighlight = false;
              }
              return;
            }
            const idx = messages.findIndex(function (m) {
              return m.id === assistantId;
            });
            if (idx >= 0) {
              messages[idx].ttsMode = "browser";
              messages[idx].ttsText = ttsText;
            }
            showTtsListenButton(assistantId, true);
            return;
          }
          if (data.error) {
            throw new Error(userFacingError(data.error, data.code));
          }
        }

        if (!res.ok) {
          if (await speakWithBrowserTts(plain, activated)) {
            consumeTtsGesture();
            const idx = messages.findIndex(function (m) {
              return m.id === assistantId;
            });
            if (idx >= 0) {
              messages[idx].ttsListen = false;
              messages[idx].ttsListenHighlight = false;
            }
            setVoiceStatus("Озвучка браузера");
            setTimeout(function () {
              setVoiceStatus("");
            }, 4000);
            return;
          }
          showTtsListenButton(assistantId, true);
          if (!fromUserClick) {
            setVoiceStatus("Озвучка недоступна (HTTP " + res.status + ").");
            setTimeout(function () {
              setVoiceStatus("");
            }, 6000);
          }
          return;
        }

        const blob = await res.blob();
        if (!blob.size) {
          throw new Error("Пустой аудиофайл от сервера");
        }
        const url = URL.createObjectURL(blob);
        const idx = messages.findIndex(function (m) {
          return m.id === assistantId;
        });
        if (idx >= 0) {
          if (messages[idx].audioUrl) URL.revokeObjectURL(messages[idx].audioUrl);
          messages[idx].audioUrl = url;
          messages[idx].ttsListen = false;
          messages[idx].ttsListenHighlight = false;
          renderMessages();
          try {
            await playAudioUrlViaUnlock(url);
            consumeTtsGesture();
          } catch (_) {
            if (await playMessageAudio(assistantId)) return;
            showTtsListenButton(assistantId, true);
            setVoiceStatus("Нажмите «Прослушать» для воспроизведения");
            setTimeout(function () {
              setVoiceStatus("");
            }, 5000);
          }
        }
      } catch (e) {
        if (await speakWithBrowserTts(plain, activated)) {
          consumeTtsGesture();
          const idx = messages.findIndex(function (m) {
            return m.id === assistantId;
          });
          if (idx >= 0) {
            messages[idx].ttsListen = false;
            messages[idx].ttsListenHighlight = false;
          }
          return;
        }
        showTtsListenButton(assistantId, true);
        if (!fromUserClick) {
          setVoiceStatus(e.message || "Не удалось озвучить ответ");
          setTimeout(function () {
            setVoiceStatus("");
          }, 6000);
        }
      }
    }

    async function sendText(text, autoFromVoice) {
      const trimmed = (text || "").trim();
      if (!trimmed || loading) return;

      markTtsUserGesture();
      messages.push({ id: uid(), role: "user", content: trimmed });
      if (inputEl) inputEl.value = "";
      loading = true;
      error = null;
      updateSendButton();
      renderMessages();

      const payload = {
        sessionId: getSessionId(),
        tracking: collectTracking(),
        messages: messages
          .filter((m) => m.id !== "welcome")
          .map(({ role, content }) => ({ role, content })),
      };

      const CHAT_TIMEOUT_MS = 240000;
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), CHAT_TIMEOUT_MS);

      try {
        const res = await fetch(ajaxUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
          signal: controller.signal,
        });
        clearTimeout(timeoutId);

        const sessionHeader = res.headers.get("X-Chat-Session-Id");
        if (sessionHeader) saveSessionId(sessionHeader);

        const contentType = res.headers.get("content-type") || "";
        if (!res.ok || contentType.includes("application/json")) {
          const data = await res.json().catch(() => ({}));
          if (res.status === 504) {
            throw new Error(
            userFacingError(
              data.error ||
                "Сервер не успел ответить вовремя. Попробуйте ещё раз или сократите вопрос.",
              data.code
            )
          );
          }
          throw new Error(userFacingError(data.error, data.code) || "Ошибка " + res.status);
        }
        if (!res.body) {
          throw new Error("Сервер не вернул поток ответа");
        }

        const assistantId = uid();
        messages.push({ id: assistantId, role: "assistant", content: "" });
        renderMessages();

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let assistantText = "";

        let renderScheduled = false;
        function scheduleRender() {
          if (renderScheduled) return;
          renderScheduled = true;
          requestAnimationFrame(() => {
            renderScheduled = false;
            renderMessages();
          });
        }

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          assistantText += decoder.decode(value, { stream: true });
          const idx = messages.findIndex((m) => m.id === assistantId);
          if (idx >= 0) messages[idx].content = assistantText;
          scheduleRender();
        }
        assistantText += decoder.decode();
        assistantText = assistantText.replace(/^\s+/, "");
        assistantText = processLeadCreatedSignal(
          res.headers.get("X-Chat-Lead-Created") === "1",
          assistantText
        );
        const idxFinal = messages.findIndex((m) => m.id === assistantId);
        if (idxFinal >= 0) messages[idxFinal].content = assistantText;
        if (!assistantText.trim()) {
          throw new Error("Пустой ответ сервера. Обновите страницу и попробуйте снова.");
        }

        if (voiceReply) {
          const activated = isTtsGestureUnlocked();
          if (isMobileTtsDevice() && activated) {
            setVoiceStatus("Озвучивается…");
            await prefetchTtsForMessage(assistantId, assistantText);
          }
          await playTtsForMessage(assistantId, assistantText, activated);
          setVoiceStatus("");
        }
      } catch (e) {
        if (e.name === "AbortError") {
          error = "Ответ слишком долгий. Попробуйте короче сформулировать запрос или повторите позже.";
        } else {
          error = userFacingError(e.message, e.code);
        }
        const last = messages[messages.length - 1];
        if (last && last.role === "assistant" && !last.content) messages.pop();
        renderMessages();
      } finally {
        clearTimeout(timeoutId);
        loading = false;
        updateSendButton();
        renderMessages();
      }
    }

    async function send() {
      await sendText(inputEl ? inputEl.value : "");
    }

    function transcribeUrl() {
      const sep = ajaxUrl.indexOf("?") >= 0 ? "&" : "?";
      return ajaxUrl + sep + "action=transcribe";
    }

    async function transcribeBlob(blob) {
      const ext = blob.type.indexOf("mp4") >= 0 ? "m4a" : blob.type.indexOf("ogg") >= 0 ? "ogg" : "webm";
      const fd = new FormData();
      fd.append("audio", blob, "voice." + ext);
      const res = await fetch(transcribeUrl(), { method: "POST", body: fd });
      const raw = await res.text();
      let data = {};
      try {
        data = raw ? JSON.parse(raw) : {};
      } catch {
        if (!res.ok) {
          throw new Error("Ошибка распознавания (HTTP " + res.status + ")");
        }
      }
      if (!res.ok) {
        throw new Error(userFacingError(data.error || data.message, data.code) || "Ошибка распознавания");
      }
      return (data.text || "").trim();
    }

    function stopRecording() {
      if (useBrowserStt && recording) {
        stopBrowserRecording();
        return;
      }
      if (!mediaRecorder || mediaRecorder.state === "inactive") return;
      try {
        if (mediaRecorder.state === "recording") {
          mediaRecorder.requestData();
        }
      } catch {}
      mediaRecorder.stop();
    }

    async function stopBrowserRecording() {
      if (!recording || !useBrowserStt) return;
      if (browserSttStopTimer) {
        clearTimeout(browserSttStopTimer);
        browserSttStopTimer = null;
      }
      clearBrowserSttRestartTimer();
      recording = false;
      if (micBtn) {
        micBtn.classList.remove("draxter-aichat-mic--active");
        micBtn.setAttribute("aria-label", "Голосовое сообщение");
      }
      updateSendButton();

      voicePhase = "transcribing";
      loading = true;
      error = null;
      setVoiceStatus("Распознаём в браузере…");
      renderMessages();

      try {
        const text = await stopBrowserStt();
        releaseBrowserMic();
        voicePhase = "idle";
        setVoiceStatus("");
        loading = false;
        if (!text) {
          error = browserSttEmptyError();
          renderMessages();
          return;
        }
        if (inputEl) inputEl.value = text;
        await sendText(text, true);
      } catch (e) {
        releaseBrowserMic();
        voicePhase = "idle";
        setVoiceStatus("");
        loading = false;
        error = userFacingError(e.message) || "Ошибка голоса";
        renderMessages();
      }
    }

    async function startRecording() {
      if (loading || recording) return;
      if (!voiceEnabled) {
        error =
          "Голосовой ввод отключён. Включите «Голосовой ввод» в настройках модуля (вкладка «Голос»).";
        renderMessages();
        return;
      }
      if (useBrowserStt) {
        void startBrowserRecordingFromGesture();
        return;
      }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        error = "Браузер не поддерживает запись с микрофона";
        renderMessages();
        return;
      }
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recordChunks = [];
        recordMime = pickRecordMime() || "audio/webm";
        const recOptions = recordMime ? { mimeType: recordMime } : undefined;
        mediaRecorder = recOptions ? new MediaRecorder(stream, recOptions) : new MediaRecorder(stream);
        const maxMs = voiceMaxSeconds * 1000;
        const stopTimer = setTimeout(() => stopRecording(), maxMs);

        mediaRecorder.ondataavailable = (e) => {
          if (e.data && e.data.size > 0) recordChunks.push(e.data);
        };

        mediaRecorder.onerror = () => {
          voicePhase = "idle";
          recording = false;
          setVoiceStatus("");
          error = "Ошибка записи с микрофона";
          stream.getTracks().forEach((t) => t.stop());
          renderMessages();
        };

        mediaRecorder.onstop = async () => {
          clearTimeout(stopTimer);
          stream.getTracks().forEach((t) => t.stop());
          recording = false;
          if (micBtn) {
            micBtn.classList.remove("draxter-aichat-mic--active");
            micBtn.setAttribute("aria-label", "Голосовое сообщение");
          }
          updateSendButton();

          const blobType =
            recordMime || (mediaRecorder && mediaRecorder.mimeType) || "audio/webm";
          const blob = new Blob(recordChunks, { type: blobType });
          recordChunks = [];
          mediaRecorder = null;

          if (!useBrowserStt && (!blob.size || blob.size < 400)) {
            voicePhase = "idle";
            setVoiceStatus("");
            error = "Не удалось записать звук. Проверьте микрофон и разрешение браузера.";
            renderMessages();
            return;
          }

          voicePhase = "transcribing";
          loading = true;
          error = null;
          setVoiceStatus("Распознаём речь…");
          renderMessages();

          try {
            const text = await transcribeBlob(blob);
            voicePhase = "idle";
            setVoiceStatus("");
            loading = false;
            if (!text) {
              error =
                "Не удалось распознать речь. Говорите чётче или проверьте настройки STT на вкладке «Голос» модуля.";
              renderMessages();
              return;
            }
            if (inputEl) inputEl.value = text;
            await sendText(text, true);
          } catch (e) {
            voicePhase = "idle";
            setVoiceStatus("");
            loading = false;
            error = userFacingError(e.message) || "Ошибка голоса";
            renderMessages();
          }
        };

        mediaRecorder.start(250);
        recording = true;
        voicePhase = "recording";
        recordStartedAt = Date.now();
        setVoiceStatus("Запись… Нажмите микрофон ещё раз, чтобы отправить");
        if (micBtn) {
          micBtn.classList.add("draxter-aichat-mic--active");
          micBtn.setAttribute("aria-label", "Остановить запись и отправить");
        }
        updateSendButton();
      } catch (e) {
        voicePhase = "idle";
        setVoiceStatus("");
        error = "Нет доступа к микрофону. Разрешите доступ в настройках браузера.";
        renderMessages();
      }
    }

    function buildPanel() {
      panel = document.createElement("div");
      panel.className = "draxter-aichat-panel";
      panel.setAttribute("role", "dialog");
      const micHtml = voiceEnabled
        ? '<button type="button" class="draxter-aichat-mic" aria-label="Голосовое сообщение" title="Голосовое сообщение">' +
          MIC_SVG +
          "</button>"
        : "";
      const voiceStatusHtml =
        voiceEnabled || voiceReply
          ? '<p class="draxter-aichat-voice-status" hidden></p>'
          : "";
      const statusHtml = showOnline
        ? '<p class="draxter-aichat-status">' +
          '<span class="draxter-aichat-status-dot" aria-hidden="true"></span>' +
          '<span class="draxter-aichat-status-text"></span></p>'
        : "";
      panel.innerHTML =
        '<header class="draxter-aichat-header">' +
        '<div class="draxter-aichat-header-main">' +
        avatarHtml("draxter-aichat-header-avatar") +
        '<div class="draxter-aichat-header-text">' +
        '<p class="draxter-aichat-title"></p>' +
        statusHtml +
        "</div></div>" +
        (embedded ? "" : '<button type="button" class="draxter-aichat-close" aria-label="Закрыть">✕</button>') +
        "</header>" +
        '<div class="draxter-aichat-messages"></div>' +
        '<footer class="draxter-aichat-footer">' +
        voiceStatusHtml +
        '<div class="draxter-aichat-footer-row">' +
        micHtml +
        '<textarea class="draxter-aichat-input" rows="1" placeholder="Сообщение…"></textarea>' +
        '<button type="button" class="draxter-aichat-send" aria-label="Отправить">➤</button>' +
        "</div></footer>";

      panel.querySelector(".draxter-aichat-title").textContent = agentName;
      const statusEl = panel.querySelector(".draxter-aichat-status-text");
      if (statusEl) statusEl.textContent = statusText;
      listEl = panel.querySelector(".draxter-aichat-messages");
      listEl.addEventListener("click", function (e) {
        const btn = e.target.closest(".draxter-aichat-tts-btn");
        if (!btn) return;
        const msgId = btn.getAttribute("data-msg-id");
        const msg = messages.find(function (m) {
          return m.id === msgId;
        });
        if (msg && msg.content) {
          markTtsUserGesture();
          playTtsForMessage(msgId, msg.content, true);
        }
      });
      inputEl = panel.querySelector(".draxter-aichat-input");
      sendBtn = panel.querySelector(".draxter-aichat-send");
      micBtn = panel.querySelector(".draxter-aichat-mic");
      voiceStatusEl = panel.querySelector(".draxter-aichat-voice-status");

      if (!embedded) {
        panel.querySelector(".draxter-aichat-close").addEventListener("click", closePanel);
      }

      sendBtn.addEventListener("click", function () {
        markTtsUserGesture();
        send();
      });
      inputEl.addEventListener("input", updateSendButton);
      inputEl.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey) {
          e.preventDefault();
          markTtsUserGesture();
          send();
        }
      });

      if (micBtn) {
        micBtn.addEventListener("click", () => {
          markTtsUserGesture();
          if (recording) stopRecording();
          else if (useBrowserStt) void startBrowserRecordingFromGesture();
          else startRecording();
        });
      }

      updateSendButton();
      renderMessages();
      return panel;
    }

    function openPanel() {
      if (!panelMounted) {
        panelMounted = true;
        root.appendChild(buildPanel());
      }
      warmBrowserMicOnce();
      closing = false;
      panel.classList.remove("draxter-aichat-panel--closing");
      requestAnimationFrame(() => {
        panelOpen = true;
        root.classList.add("draxter-aichat-root--panel-open");
        setFabState(true);
        setTimeout(() => inputEl && inputEl.focus(), 200);
      });
    }

    function closePanel() {
      if (embedded) return;
      stopTtsPlayback();
      if (recording) stopRecording();
      closing = true;
      panelOpen = false;
      root.classList.remove("draxter-aichat-root--panel-open");
      panel.classList.add("draxter-aichat-panel--closing");
      setFabState(false);
      setTimeout(() => {
        if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
        panelMounted = false;
        panel = null;
        closing = false;
      }, CLOSE_MS);
    }

    if (embedded) {
      root.appendChild(buildPanel());
      warmBrowserMicOnce();
      panelOpen = true;
      root.classList.add("draxter-aichat-root--panel-open");
      return;
    }

    fab = document.createElement("button");
    fab.type = "button";
    fab.className = "draxter-aichat-fab";
    fab.setAttribute("aria-expanded", "false");
    fab.innerHTML = fabClosedHtml();

    fab.addEventListener("click", () => {
      if (panelOpen && panelMounted && !closing) closePanel();
      else openPanel();
    });

    root.appendChild(fab);
  }

  document.querySelectorAll(".draxter-aichat-root").forEach(initRoot);
})();

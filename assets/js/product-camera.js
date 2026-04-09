(() => {
  'use strict';

  const i18n = window.PRODUCT_CAMERA_I18N || {};
  const config = window.PRODUCT_CAMERA_CONFIG || {};
  const preferredFormats = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'];
  let supportPromise = null;
  let modal = null;
  let detector = null;
  let supportedFormats = [];
  let activeStream = null;
  let activeVideo = null;
  let activeResolve = null;
  let activeControls = null;
  let scanFrameId = 0;
  let fallbackLoadPromise = null;
  let fallbackReader = null;
  let scanCanvas = null;
  let scanContext = null;

  function text(key, fallback) {
    return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : fallback;
  }

  function isLikelyMobile() {
    const ua = String(navigator.userAgent || '');
    const coarse = typeof window.matchMedia === 'function' ? window.matchMedia('(pointer: coarse)').matches : false;
    const touch = (navigator.maxTouchPoints || 0) > 0;
    const platform = String(navigator.platform || '');
    const mobileUa = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|Windows Phone/i.test(ua);
    const ipadOsDesktopUa = platform === 'MacIntel' && touch;
    const viewportEdge = Math.min(
      Number(window.innerWidth || 0) || Number(window.screen?.width || 0) || 0,
      Number(window.innerHeight || 0) || Number(window.screen?.height || 0) || 0
    );
    const compactTouchViewport = coarse && touch && viewportEdge > 0 && viewportEdge <= 1024;
    return mobileUa || ipadOsDesktopUa || compactTouchViewport;
  }

  function canAccessCamera() {
    return !!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function');
  }

  function hasNativeBarcodeDetector() {
    return typeof window.BarcodeDetector === 'function';
  }

  function hasFallbackScannerConfig() {
    return String(config.fallbackLibUrl || '').trim() !== '';
  }

  function buildVideoConstraints() {
    return {
      audio: false,
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1920 },
        height: { ideal: 1080 },
        frameRate: { ideal: 30, max: 60 },
      },
    };
  }

  function getTrackCapabilities(track) {
    if (!track || typeof track.getCapabilities !== 'function') {
      return {};
    }
    try {
      return track.getCapabilities() || {};
    } catch (_) {
      return {};
    }
  }

  async function enhanceTrack(track) {
    if (!track || typeof track.applyConstraints !== 'function') {
      return;
    }

    const caps = getTrackCapabilities(track);
    const advanced = [];

    if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
      advanced.push({ focusMode: 'continuous' });
    }
    if (Array.isArray(caps.exposureMode) && caps.exposureMode.includes('continuous')) {
      advanced.push({ exposureMode: 'continuous' });
    }
    if (Array.isArray(caps.whiteBalanceMode) && caps.whiteBalanceMode.includes('continuous')) {
      advanced.push({ whiteBalanceMode: 'continuous' });
    }

    if (!advanced.length) {
      return;
    }

    try {
      await track.applyConstraints({ advanced });
    } catch (_) {
      // ignore unsupported advanced constraints
    }
  }

  function getVideoTrackFromSource(video = null, stream = null) {
    const mediaStream = stream || video?.srcObject || null;
    if (!mediaStream || typeof mediaStream.getVideoTracks !== 'function') {
      return null;
    }
    return mediaStream.getVideoTracks()[0] || null;
  }

  async function isSupported() {
    if (supportPromise) {
      return supportPromise;
    }

    supportPromise = (async () => {
      if (!isLikelyMobile()) {
        return false;
      }
      if (!canAccessCamera()) {
        return false;
      }
      if (!hasNativeBarcodeDetector()) {
        return hasFallbackScannerConfig();
      }
      try {
        supportedFormats = typeof window.BarcodeDetector.getSupportedFormats === 'function'
          ? await window.BarcodeDetector.getSupportedFormats()
          : preferredFormats.slice();
        return preferredFormats.some((format) => supportedFormats.includes(format));
      } catch (_) {
        supportedFormats = preferredFormats.slice();
        return true;
      }
    })();

    return supportPromise;
  }

  function ensureModal() {
    if (modal) {
      return modal;
    }

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay product-camera-modal-overlay hidden';
    overlay.innerHTML = `
      <div class="modal product-camera-modal" role="dialog" aria-modal="true" aria-label="${text('title', 'Scan barcode')}">
        <div class="modal-header">
          <span class="modal-title">${text('title', 'Scan barcode')}</span>
          <button type="button" class="modal-close" data-camera-close>${typeof feather !== 'undefined' ? feather.icons.x.toSvg({ width: 18, height: 18 }) : '&times;'}</button>
        </div>
        <div class="modal-body">
          <div class="product-camera-preview-wrap">
            <video class="product-camera-preview" autoplay playsinline muted></video>
            <div class="product-camera-overlay-frame"></div>
          </div>
          <div class="product-camera-status" data-camera-status>${text('hint', 'Point the camera at the barcode.')}</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-camera-close>${text('close', 'Close')}</button>
        </div>
      </div>
    `;

    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        closeScanner();
      }
    });
    overlay.querySelectorAll('[data-camera-close]').forEach((button) => {
      button.addEventListener('click', () => closeScanner());
    });
    document.body.appendChild(overlay);
    modal = {
      overlay,
      video: overlay.querySelector('video'),
      status: overlay.querySelector('[data-camera-status]'),
    };
    return modal;
  }

  function setStatus(message) {
    const current = ensureModal();
    if (current.status) {
      current.status.textContent = message;
    }
  }

  function stopStream() {
    if (scanFrameId) {
      window.cancelAnimationFrame(scanFrameId);
      scanFrameId = 0;
    }
    if (activeControls && typeof activeControls.stop === 'function') {
      try {
        activeControls.stop();
      } catch (_) {
        // ignore
      }
      activeControls = null;
    }
    if (activeVideo) {
      activeVideo.pause();
      activeVideo.srcObject = null;
      activeVideo = null;
    }
    if (activeStream) {
      activeStream.getTracks().forEach((track) => {
        try {
          track.stop();
        } catch (_) {
          // ignore
        }
      });
      activeStream = null;
    }
  }

  function resetSupportDetection() {
    supportPromise = null;
  }

  function resolveAndClose(value) {
    const resolver = activeResolve;
    activeResolve = null;
    stopStream();
    if (modal) {
      modal.overlay.classList.add('hidden');
    }
    if (resolver) {
      resolver(value);
    }
  }

  function closeScanner() {
    resolveAndClose(null);
  }

  function ensureFallbackLibrary() {
    if (window.ZXingBrowser && typeof window.ZXingBrowser.BrowserMultiFormatReader === 'function') {
      return Promise.resolve(window.ZXingBrowser);
    }

    const src = String(config.fallbackLibUrl || '').trim();
    if (!src) {
      return Promise.reject(new Error(text('unsupported', 'Camera scanning is available only on supported mobile devices.')));
    }

    if (!fallbackLoadPromise) {
      fallbackLoadPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => {
          if (window.ZXingBrowser && typeof window.ZXingBrowser.BrowserMultiFormatReader === 'function') {
            resolve(window.ZXingBrowser);
            return;
          }
          reject(new Error(text('unavailable', 'Unable to access the camera. You can continue with manual search.')));
        };
        script.onerror = () => reject(new Error(text('unavailable', 'Unable to access the camera. You can continue with manual search.')));
        document.head.appendChild(script);
      }).catch((error) => {
        fallbackLoadPromise = null;
        throw error;
      });
    }

    return fallbackLoadPromise;
  }

  function getScanCanvas(width, height) {
    if (!scanCanvas) {
      scanCanvas = document.createElement('canvas');
      scanContext = scanCanvas.getContext('2d', { willReadFrequently: true }) || scanCanvas.getContext('2d');
    }
    if (!scanCanvas || !scanContext) {
      return null;
    }
    if (scanCanvas.width !== width) {
      scanCanvas.width = width;
    }
    if (scanCanvas.height !== height) {
      scanCanvas.height = height;
    }
    scanContext.imageSmoothingEnabled = false;
    scanContext.clearRect(0, 0, width, height);
    return scanCanvas;
  }

  function getScanRegions(video) {
    const width = Number(video?.videoWidth || video?.clientWidth || 0);
    const height = Number(video?.videoHeight || video?.clientHeight || 0);
    if (!width || !height) {
      return [{ x: 0, y: 0, width: 0, height: 0, full: true }];
    }

    const marginX = Math.max(0, Math.round(width * 0.08));
    const regionWidth = Math.max(1, width - marginX * 2);
    const bandHeight = Math.min(height, Math.max(110, Math.round(height * 0.2)));
    const centerY = Math.max(0, Math.round((height - bandHeight) / 2));
    const lowerY = Math.max(0, Math.min(height - bandHeight, Math.round(height * 0.58)));

    return [
      { x: marginX, y: centerY, width: regionWidth, height: bandHeight },
      { x: marginX, y: lowerY, width: regionWidth, height: bandHeight },
      { x: 0, y: 0, width, height, full: true },
    ];
  }

  async function detectNativeValue(video) {
    const barcodeDetector = await getDetector();
    const regions = getScanRegions(video);

    for (const region of regions) {
      let source = video;

      if (!region.full) {
        const canvas = getScanCanvas(region.width, region.height);
        if (!canvas || !scanContext) {
          continue;
        }
        scanContext.drawImage(
          video,
          region.x,
          region.y,
          region.width,
          region.height,
          0,
          0,
          region.width,
          region.height
        );
        source = canvas;
      }

      const barcodes = await barcodeDetector.detect(source);
      if (!Array.isArray(barcodes) || !barcodes.length) {
        continue;
      }

      const rawValue = String(barcodes[0].rawValue || '').trim();
      if (rawValue) {
        return rawValue;
      }
    }

    return '';
  }

  function buildFallbackHints(ZXingBrowser) {
    const hints = new Map();
    const barcodeFormats = ZXingBrowser?.BarcodeFormat || {};
    const possibleFormats = [
      barcodeFormats.EAN_13,
      barcodeFormats.EAN_8,
      barcodeFormats.UPC_A,
      barcodeFormats.UPC_E,
      barcodeFormats.CODE_128,
      barcodeFormats.CODE_39,
      barcodeFormats.QR_CODE,
    ].filter((format) => format !== undefined && format !== null);

    if (possibleFormats.length) {
      hints.set(ZXingBrowser?.DecodeHintType?.POSSIBLE_FORMATS ?? 2, possibleFormats);
    }
    hints.set(ZXingBrowser?.DecodeHintType?.TRY_HARDER ?? 3, true);

    return hints;
  }

  async function getDetector() {
    if (!detector) {
      const formats = preferredFormats.filter((format) => !supportedFormats.length || supportedFormats.includes(format));
      detector = new window.BarcodeDetector({ formats: formats.length ? formats : preferredFormats });
    }
    return detector;
  }

  async function scanLoop() {
    const current = ensureModal();
    if (!activeStream || !current.video || current.video.readyState < 2) {
      scanFrameId = window.requestAnimationFrame(scanLoop);
      return;
    }

    try {
      const rawValue = await detectNativeValue(current.video);
      if (rawValue) {
        resolveAndClose(rawValue);
        return;
      }
    } catch (_) {
      // ignore intermittent detector errors and continue scanning
    }

    scanFrameId = window.requestAnimationFrame(scanLoop);
  }

  async function openScanner() {
    const supported = await isSupported();
    if (!supported) {
      throw new Error(text('unsupported', 'Camera scanning is available only on supported mobile devices.'));
    }

    if (!hasNativeBarcodeDetector()) {
      return openFallbackScanner();
    }

    const current = ensureModal();
    current.overlay.classList.remove('hidden');
    setStatus(text('scanning', 'Scanning...'));

    try {
      activeStream = await navigator.mediaDevices.getUserMedia(buildVideoConstraints());
      activeVideo = current.video;
      activeVideo.srcObject = activeStream;
      await activeVideo.play();
      await enhanceTrack(getVideoTrackFromSource(activeVideo, activeStream));
      return await new Promise((resolve) => {
        activeResolve = resolve;
        scanLoop();
      });
    } catch (error) {
      stopStream();
      current.overlay.classList.add('hidden');
      const name = String(error?.name || '');
      if (name === 'NotAllowedError' || name === 'SecurityError') {
        throw new Error(text('denied', 'Camera access was denied. Allow access in the browser and try again.'));
      }
      throw new Error(text('unavailable', 'Unable to access the camera. You can continue with manual search.'));
    }
  }

  async function openFallbackScanner() {
    if (!canAccessCamera()) {
      throw new Error(text('unsupported', 'Camera scanning is available only on supported mobile devices.'));
    }

    const current = ensureModal();
    current.overlay.classList.remove('hidden');
    setStatus(text('scanning', 'Scanning...'));

    try {
      const ZXingBrowser = await ensureFallbackLibrary();
      if (!fallbackReader) {
        fallbackReader = new ZXingBrowser.BrowserMultiFormatReader(
          buildFallbackHints(ZXingBrowser),
          {
            delayBetweenScanAttempts: 120,
            delayBetweenScanSuccess: 220,
            tryPlayVideoTimeout: 8000,
          }
        );
      }

      activeVideo = current.video;
      const constraints = buildVideoConstraints();

      return await new Promise(async (resolve, reject) => {
        activeResolve = resolve;
        try {
          activeControls = await fallbackReader.decodeFromConstraints(
            constraints,
            activeVideo,
            (result) => {
              const rawValue = String(
                result?.getText?.()
                || result?.text
                || result?.rawValue
                || ''
              ).trim();
              if (!rawValue) {
                return;
              }
              resolveAndClose(rawValue);
            }
          );
          await enhanceTrack(getVideoTrackFromSource(activeVideo));
        } catch (error) {
          activeResolve = null;
          stopStream();
          current.overlay.classList.add('hidden');
          const name = String(error?.name || '');
          if (name === 'NotAllowedError' || name === 'SecurityError') {
            reject(new Error(text('denied', 'Camera access was denied. Allow access in the browser and try again.')));
            return;
          }
          reject(new Error(text('unavailable', 'Unable to access the camera. You can continue with manual search.')));
        }
      });
    } catch (error) {
      stopStream();
      current.overlay.classList.add('hidden');
      throw error;
    }
  }

  async function attach(button, options = {}) {
    if (!button) {
      return false;
    }

    const supported = await isSupported();
    if (!supported) {
      button.classList.remove('is-visible');
      button.setAttribute('hidden', 'hidden');
      return false;
    }

    button.removeAttribute('hidden');
    button.classList.add('is-visible');
    if (!button.dataset.cameraBound) {
      button.dataset.cameraBound = '1';
      button.addEventListener('click', async () => {
        try {
          const code = await openScanner();
          if (!code) {
            return;
          }
          if (typeof options.onDetected === 'function') {
            await options.onDetected(code);
          }
        } catch (error) {
          if (typeof options.onError === 'function') {
            options.onError(error);
            return;
          }
          if (typeof window.showToast === 'function') {
            window.showToast(error.message || text('failed', 'The scanner could not read the barcode. Try again or use manual search.'), 'warning');
          } else {
            window.alert(error.message || text('failed', 'The scanner could not read the barcode. Try again or use manual search.'));
          }
        }
      });
    }

    return true;
  }

  function resolveTarget(target) {
    if (!target) {
      return null;
    }
    if (typeof target === 'string') {
      try {
        return document.querySelector(target);
      } catch (_) {
        return null;
      }
    }
    return target instanceof Element ? target : null;
  }

  function emitInputEvents(input) {
    if (!input) {
      return;
    }
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  async function bindBarcodeField(button, target, options = {}) {
    const input = resolveTarget(target);
    if (!button || !input) {
      return false;
    }

    return attach(button, {
      onDetected: async (code) => {
        input.value = code;
        emitInputEvents(input);
        if (typeof options.onDetected === 'function') {
          await options.onDetected(code, input);
        }
      },
      onError: options.onError,
    });
  }

  async function initBarcodeFields(root = document) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return [];
    }

    const buttons = Array.from(root.querySelectorAll('[data-barcode-camera]'));
    return Promise.all(buttons.map((button) => {
      const targetSelector = button.getAttribute('data-camera-target');
      if (!targetSelector) {
        return Promise.resolve(false);
      }
      return bindBarcodeField(button, targetSelector);
    }));
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('pageshow', resetSupportDetection, { passive: true });
  }

  window.ProductCameraScanner = {
    isSupported,
    attach,
    bindBarcodeField,
    initBarcodeFields,
    open: openScanner,
    close: closeScanner,
  };

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        initBarcodeFields(document);
      }, { once: true });
    } else {
      initBarcodeFields(document);
    }
  }
})();

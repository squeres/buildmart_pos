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
    overlay.className = 'modal-overlay hidden';
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
      const barcodeDetector = await getDetector();
      const barcodes = await barcodeDetector.detect(current.video);
      if (Array.isArray(barcodes) && barcodes.length) {
        const rawValue = String(barcodes[0].rawValue || '').trim();
        if (rawValue) {
          resolveAndClose(rawValue);
          return;
        }
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

    const constraints = {
      audio: false,
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 },
      },
    };

    try {
      activeStream = await navigator.mediaDevices.getUserMedia(constraints);
      activeVideo = current.video;
      activeVideo.srcObject = activeStream;
      await activeVideo.play();
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
        fallbackReader = new ZXingBrowser.BrowserMultiFormatReader();
      }

      activeVideo = current.video;
      const constraints = {
        audio: false,
        video: {
          facingMode: { ideal: 'environment' },
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
      };

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

  if (typeof window !== 'undefined') {
    window.addEventListener('pageshow', resetSupportDetection, { passive: true });
  }

  window.ProductCameraScanner = {
    isSupported,
    attach,
    open: openScanner,
    close: closeScanner,
  };
})();

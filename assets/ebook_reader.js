import * as pdfjsLib from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.5.207/build/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.5.207/build/pdf.worker.min.mjs';

const DEFAULT_ZOOM = 1;
const ZOOM_STEP = 0.12;
const MIN_ZOOM = 0.72;
const MAX_ZOOM = 2.1;
const VIEWPORT_RENDER_MARGIN = 1.5;
const PAGE_BUFFER = 2;
const DESKTOP_BATCH_SIZE = 30;
const MOBILE_BATCH_SIZE = 5;
const MOBILE_LAYOUT_QUERY = '(max-width: 768px)';

async function renderReader(root) {
  const pdfUrl = root.dataset.pdfUrl || '';
  const stage = root.querySelector('[data-ebook-stage]');
  const loading = root.querySelector('[data-ebook-loading]');
  const pageLabel = root.querySelector('[data-ebook-page-label]');
  const prevButtons = Array.from(root.querySelectorAll('[data-ebook-prev]'));
  const nextButtons = Array.from(root.querySelectorAll('[data-ebook-next]'));
  const fitWidthButton = root.querySelector('[data-ebook-fit-width]');
  const zoomOutButton = root.querySelector('[data-ebook-zoom-out]');
  const zoomInButton = root.querySelector('[data-ebook-zoom-in]');
  const pageInput = root.querySelector('[data-ebook-page-input]');
  const pageJumpButton = root.querySelector('[data-ebook-page-jump]');
  const stageWrap = root.querySelector('.ebook-reader-stage-wrap');

  if (!pdfUrl || !stage || !loading || !pageLabel || prevButtons.length === 0 || nextButtons.length === 0 || !fitWidthButton || !zoomOutButton || !zoomInButton) {
    return;
  }

  let pdfDocument = null;
  let zoomLevel = DEFAULT_ZOOM;
  let currentPage = 1;
  let isRendering = false;
  let resizeTimer = 0;
  let scrollFrame = 0;
  let batchStart = 1;
  let lastWindowWidth = window.innerWidth;
  const pageStates = new Map();
  const pageMetrics = new Map();
  const mobileLayout = window.matchMedia(MOBILE_LAYOUT_QUERY);
  let lastLayoutMode = mobileLayout.matches ? 'mobile' : 'desktop';

  const isMobileLayout = () => mobileLayout.matches;
  const getPageShells = () => Array.from(stage.querySelectorAll('[data-page-number]'));
  const getBatchSize = () => (isMobileLayout() ? MOBILE_BATCH_SIZE : DESKTOP_BATCH_SIZE);
  const getBatchEnd = () => Math.min((pdfDocument?.numPages || 0), batchStart + getBatchSize() - 1);
  const getBatchStartForPage = (pageNumber) => Math.floor((pageNumber - 1) / getBatchSize()) * getBatchSize() + 1;

  const getScaleForDimensions = (baseWidth) => {
    const stageWidth = Math.max(stage.clientWidth || 0, 320);
    const usableWidth = Math.max(stageWidth - 36, 220);
    const fitScale = usableWidth / Math.max(baseWidth, 1);
    return Math.max(0.8, Math.min(fitScale * zoomLevel, 2.8));
  };

  const getEffectiveScale = (page) => {
    const baseViewport = page.getViewport({ scale: 1 });
    return getScaleForDimensions(baseViewport.width);
  };

  const getViewportForPageNumber = (pageNumber) => {
    const metric = pageMetrics.get(pageNumber);
    if (!metric) {
      return null;
    }

    const scale = getScaleForDimensions(metric.width);
    return {
      width: Math.floor(metric.width * scale),
      height: Math.floor(metric.height * scale),
    };
  };

  const updateShellHeights = () => {
    getPageShells().forEach((pageCard) => {
      const pageNumber = Number(pageCard.getAttribute('data-page-number') || '0');
      const viewport = getViewportForPageNumber(pageNumber);
      if (!viewport) {
        return;
      }

      pageCard.style.minHeight = `${viewport.height + 56}px`;
    });
  };

  const updateControls = () => {
    if (!pdfDocument) {
      return;
    }

    const fitWidthActive = Math.abs(zoomLevel - DEFAULT_ZOOM) < 0.01;
    fitWidthButton.setAttribute('aria-pressed', fitWidthActive ? 'true' : 'false');
    fitWidthButton.classList.toggle('is-active', fitWidthActive);

    if (isMobileLayout()) {
      const batchEnd = getBatchEnd();
      pageLabel.textContent = `Pages ${batchStart}-${batchEnd} of ${pdfDocument.numPages} | ${Math.round(zoomLevel * 100)}%`;
      prevButtons.forEach((button) => {
        button.textContent = `Previous ${MOBILE_BATCH_SIZE}`;
        button.disabled = batchStart <= 1 || isRendering;
      });
      nextButtons.forEach((button) => {
        button.textContent = `Next ${MOBILE_BATCH_SIZE}`;
        button.disabled = batchEnd >= pdfDocument.numPages || isRendering;
      });
      return;
    }

    const batchEnd = getBatchEnd();
    pageLabel.textContent = `Pages ${batchStart}-${batchEnd} of ${pdfDocument.numPages} | Page ${currentPage} | ${Math.round(zoomLevel * 100)}%`;
    prevButtons.forEach((button) => {
      button.textContent = `Previous ${DESKTOP_BATCH_SIZE}`;
      button.disabled = batchStart <= 1 || isRendering;
    });
    nextButtons.forEach((button) => {
      button.textContent = `Next ${DESKTOP_BATCH_SIZE}`;
      button.disabled = batchEnd >= pdfDocument.numPages || isRendering;
    });
  };

  const updateCurrentPageFromScroll = () => {
    if (!pdfDocument || isMobileLayout()) {
      return;
    }

    const pages = getPageShells();
    if (pages.length === 0) {
      return;
    }

    const viewportTop = window.innerHeight * 0.24;
    let activePage = currentPage;
    let closestDistance = Number.POSITIVE_INFINITY;

    pages.forEach((pageCard) => {
      const pageNumber = Number(pageCard.getAttribute('data-page-number') || '0');
      const rect = pageCard.getBoundingClientRect();
      const distance = Math.abs(rect.top - viewportTop);
      if (distance < closestDistance) {
        closestDistance = distance;
        activePage = pageNumber;
      }
    });

    if (activePage !== currentPage) {
      currentPage = activePage;
      updateControls();
    }
  };

  const scrollToPage = (pageNumber) => {
    const target = stage.querySelector(`[data-page-number="${pageNumber}"]`);
    if (!target) {
      return;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    currentPage = pageNumber;
    updateControls();
  };

  const wait = (ms) => new Promise((resolve) => {
    window.setTimeout(resolve, ms);
  });

  const nextFrame = () => new Promise((resolve) => {
    window.requestAnimationFrame(() => resolve());
  });

  const animateBatchChange = async () => {
    stage.classList.add('is-batch-transitioning');
    if (stageWrap) {
      stageWrap.classList.add('is-batch-transitioning');
      stageWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      stage.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    await wait(180);
  };

  const finishBatchChange = async () => {
    await nextFrame();
    stage.classList.add('is-batch-entering');
    if (stageWrap) {
      stageWrap.classList.add('is-batch-entering');
    }
    await wait(220);
    stage.classList.remove('is-batch-entering');
    stage.classList.remove('is-batch-transitioning');
    if (stageWrap) {
      stageWrap.classList.remove('is-batch-entering');
      stageWrap.classList.remove('is-batch-transitioning');
    }
  };

  const buildPageShells = () => {
    if (!pdfDocument) {
      return;
    }

    const stageHeight = stage.getBoundingClientRect().height;
    if (stageHeight > 0) {
      stage.style.minHeight = `${Math.ceil(stageHeight)}px`;
    }

    stage.innerHTML = '';

    try {
      const startPage = batchStart;
      const endPage = getBatchEnd();

      for (let pageNumber = startPage; pageNumber <= endPage; pageNumber += 1) {
        const pageCard = document.createElement('section');
        pageCard.className = 'ebook-reader-page';
        pageCard.setAttribute('data-page-number', String(pageNumber));

        const pageMeta = document.createElement('div');
        pageMeta.className = 'ebook-reader-page-meta';
        pageMeta.textContent = `Page ${pageNumber}`;

        const canvasHost = document.createElement('div');
        canvasHost.className = 'ebook-reader-canvas-host';
        canvasHost.setAttribute('data-ebook-canvas-host', 'true');
        const viewport = getViewportForPageNumber(pageNumber);
        if (viewport) {
          canvasHost.style.minHeight = `${viewport.height}px`;
        }

        pageCard.appendChild(pageMeta);
        pageCard.appendChild(canvasHost);
        stage.appendChild(pageCard);
        pageStates.set(pageNumber, { rendered: false, rendering: false });
      }

      updateShellHeights();
      updateControls();
    } finally {
      stage.style.minHeight = '';
    }
  };

  const renderPage = async (pageNumber) => {
    if (!pdfDocument) {
      return;
    }

    const pageCard = stage.querySelector(`[data-page-number="${pageNumber}"]`);
    const canvasHost = pageCard?.querySelector('[data-ebook-canvas-host]');
    const pageState = pageStates.get(pageNumber);

    if (!pageCard || !canvasHost || !pageState || pageState.rendered || pageState.rendering) {
      return;
    }

    pageState.rendering = true;

    try {
      const page = await pdfDocument.getPage(pageNumber);
      const viewport = page.getViewport({ scale: getEffectiveScale(page) });

      const canvas = document.createElement('canvas');
      canvas.className = 'ebook-reader-canvas';
      canvas.width = Math.floor(viewport.width);
      canvas.height = Math.floor(viewport.height);

      const context = canvas.getContext('2d', { alpha: false });
      canvasHost.innerHTML = '';
      canvasHost.appendChild(canvas);

      await page.render({
        canvasContext: context,
        viewport,
      }).promise;

      pageState.rendered = true;
      pageCard.setAttribute('data-ebook-rendered-page', 'true');
    } finally {
      pageState.rendering = false;
      updateControls();
    }
  };

  const getVisiblePageRange = () => {
    if (isMobileLayout()) {
      return {
        start: batchStart,
        end: getBatchEnd(),
      };
    }

    const pages = getPageShells();
    if (pages.length === 0) {
      return { start: batchStart, end: getBatchEnd() };
    }

    const renderTop = -window.innerHeight * VIEWPORT_RENDER_MARGIN;
    const renderBottom = window.innerHeight * (1 + VIEWPORT_RENDER_MARGIN);
    let start = null;
    let end = null;

    pages.forEach((pageCard) => {
      const pageNumber = Number(pageCard.getAttribute('data-page-number') || '0');
      const rect = pageCard.getBoundingClientRect();
      const inRange = rect.bottom >= renderTop && rect.top <= renderBottom;
      if (!inRange) {
        return;
      }

      if (start === null) {
        start = pageNumber;
      }
      end = pageNumber;
    });

    if (start === null || end === null) {
      return {
        start: Math.max(batchStart, currentPage - PAGE_BUFFER),
        end: Math.min(getBatchEnd(), currentPage + PAGE_BUFFER),
      };
    }

    return {
      start: Math.max(batchStart, start - PAGE_BUFFER),
      end: Math.min(getBatchEnd(), end + PAGE_BUFFER),
    };
  };

  const syncVisiblePages = async () => {
    if (!pdfDocument || isRendering) {
      return;
    }

    isRendering = true;
    updateControls();

    try {
      const { start, end } = getVisiblePageRange();

      for (let pageNumber = start; pageNumber <= end; pageNumber += 1) {
        await renderPage(pageNumber);
      }
    } finally {
      isRendering = false;
      updateControls();
    }
  };

  const rebuildReader = async ({ scrollToTop = false } = {}) => {
    pageStates.clear();
    buildPageShells();
    await syncVisiblePages();

    if (isMobileLayout()) {
      if (scrollToTop) {
        stage.scrollIntoView({ behavior: 'auto', block: 'start' });
      }
      return;
    }

    if (scrollToTop) {
      stage.scrollIntoView({ behavior: 'auto', block: 'start' });
      return;
    }

    scrollToPage(currentPage);
  };

  const goToPreviousPage = async () => {
    if (!pdfDocument || isRendering) {
      return;
    }

    if (batchStart <= 1) {
      return;
    }

    batchStart = Math.max(1, batchStart - getBatchSize());
    currentPage = batchStart;
    pageLabel.textContent = 'Loading pages...';
    await animateBatchChange();
    await rebuildReader();
    await finishBatchChange();
  };

  const goToNextPage = async () => {
    if (!pdfDocument || isRendering) {
      return;
    }

    const batchEnd = getBatchEnd();
    if (batchEnd >= pdfDocument.numPages) {
      return;
    }

    batchStart = batchEnd + 1;
    currentPage = batchStart;
    pageLabel.textContent = 'Loading pages...';
    await animateBatchChange();
    await rebuildReader();
    await finishBatchChange();
  };

  const goToPage = async (pageNumber) => {
    if (!pdfDocument || isRendering) {
      return;
    }

    const safePageNumber = Math.max(1, Math.min(pdfDocument.numPages, pageNumber));

    batchStart = getBatchStartForPage(safePageNumber);
    currentPage = safePageNumber;
    pageLabel.textContent = 'Loading pages...';
    await animateBatchChange();
    await rebuildReader();
    await finishBatchChange();
  };

  prevButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      await goToPreviousPage();
    });
  });

  nextButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      await goToNextPage();
    });
  });

  if (pageJumpButton && pageInput) {
    pageJumpButton.addEventListener('click', async () => {
      const targetPage = Number(pageInput.value);
      if (!Number.isFinite(targetPage) || targetPage <= 0) {
        return;
      }

      await goToPage(targetPage);
      pageInput.value = '';
    });

    pageInput.addEventListener('keydown', async (event) => {
      if (event.key !== 'Enter') {
        return;
      }

      event.preventDefault();
      pageJumpButton.click();
    });
  }

  document.addEventListener('keydown', async (event) => {
    const target = event.target;
    const tagName = target instanceof HTMLElement ? target.tagName : '';
    const isTypingTarget =
      target instanceof HTMLElement &&
      (target.isContentEditable ||
        tagName === 'INPUT' ||
        tagName === 'TEXTAREA' ||
        tagName === 'SELECT' ||
        tagName === 'BUTTON');

    if (isTypingTarget || !pdfDocument || isMobileLayout()) {
      return;
    }

    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      await goToPreviousPage();
      return;
    }

    if (event.key === 'ArrowRight') {
      event.preventDefault();
      await goToNextPage();
    }
  });

  zoomOutButton.addEventListener('click', async () => {
    zoomLevel = Math.max(MIN_ZOOM, +(zoomLevel - ZOOM_STEP).toFixed(2));
    pageLabel.textContent = 'Refreshing pages...';
    await rebuildReader();
  });

  zoomInButton.addEventListener('click', async () => {
    zoomLevel = Math.min(MAX_ZOOM, +(zoomLevel + ZOOM_STEP).toFixed(2));
    pageLabel.textContent = 'Refreshing pages...';
    await rebuildReader();
  });

  fitWidthButton.addEventListener('click', async () => {
    if (Math.abs(zoomLevel - DEFAULT_ZOOM) < 0.01) {
      return;
    }

    zoomLevel = DEFAULT_ZOOM;
    pageLabel.textContent = 'Fitting to width...';
    await rebuildReader();
  });

  window.addEventListener('resize', () => {
    if (!pdfDocument || isRendering) {
      return;
    }

    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
      const nextLayoutMode = isMobileLayout() ? 'mobile' : 'desktop';
      const widthChanged = window.innerWidth !== lastWindowWidth;
      const layoutChanged = nextLayoutMode !== lastLayoutMode;

      if (nextLayoutMode === 'mobile' && !widthChanged && !layoutChanged) {
        return;
      }

      lastWindowWidth = window.innerWidth;
      lastLayoutMode = nextLayoutMode;

      currentPage = Math.max(1, Math.min(currentPage, pdfDocument.numPages));
      batchStart = getBatchStartForPage(currentPage);

      rebuildReader()
        .then(() => {
          if (!isMobileLayout()) {
            updateCurrentPageFromScroll();
          }
        })
        .catch(() => null);
    }, 120);
  });

  window.addEventListener('scroll', () => {
    if (!pdfDocument || isRendering || isMobileLayout()) {
      return;
    }

    if (scrollFrame) {
      window.cancelAnimationFrame(scrollFrame);
    }

    scrollFrame = window.requestAnimationFrame(() => {
      updateCurrentPageFromScroll();
      syncVisiblePages().catch(() => null);
      scrollFrame = 0;
    });
  }, { passive: true });

  try {
    pdfDocument = await pdfjsLib.getDocument({
      url: pdfUrl,
      withCredentials: true,
    }).promise;
    loading.remove();

    for (let pageNumber = 1; pageNumber <= pdfDocument.numPages; pageNumber += 1) {
      const page = await pdfDocument.getPage(pageNumber);
      const viewport = page.getViewport({ scale: 1 });
      pageMetrics.set(pageNumber, {
        width: viewport.width,
        height: viewport.height,
      });
    }

    batchStart = 1;
    if (pageInput) {
      pageInput.max = String(pdfDocument.numPages);
    }
    updateControls();
    buildPageShells();
    await syncVisiblePages();
  } catch (error) {
    console.error('Unable to load eBook PDF.', error);
    loading.textContent = 'Unable to load this eBook right now.';
    pageLabel.textContent = 'Reader unavailable';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-ebook-reader]').forEach((root) => {
    renderReader(root);
  });
});

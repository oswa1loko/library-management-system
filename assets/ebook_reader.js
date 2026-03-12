import * as pdfjsLib from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.5.207/build/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.5.207/build/pdf.worker.min.mjs';

const DEFAULT_SCALE = 1.1;
const SCALE_STEP = 0.15;
const MIN_SCALE = 0.7;
const MAX_SCALE = 2.2;

async function renderReader(root) {
  const pdfUrl = root.dataset.pdfUrl || '';
  const stage = root.querySelector('[data-ebook-stage]');
  const loading = root.querySelector('[data-ebook-loading]');
  const pageLabel = root.querySelector('[data-ebook-page-label]');
  const prevButton = root.querySelector('[data-ebook-prev]');
  const nextButton = root.querySelector('[data-ebook-next]');
  const zoomOutButton = root.querySelector('[data-ebook-zoom-out]');
  const zoomInButton = root.querySelector('[data-ebook-zoom-in]');

  if (!pdfUrl || !stage || !loading || !pageLabel || !prevButton || !nextButton || !zoomOutButton || !zoomInButton) {
    return;
  }

  let pdfDocument = null;
  let scale = DEFAULT_SCALE;
  let currentPage = 1;

  const updateControls = () => {
    if (!pdfDocument) {
      return;
    }

    pageLabel.textContent = `Page ${currentPage} of ${pdfDocument.numPages} · ${Math.round(scale * 100)}%`;
    prevButton.disabled = currentPage <= 1;
    nextButton.disabled = currentPage >= pdfDocument.numPages;
  };

  const renderCurrentPage = async () => {
    if (!pdfDocument) {
      return;
    }

    const stageHeight = stage.getBoundingClientRect().height;
    if (stageHeight > 0) {
      stage.style.minHeight = `${Math.ceil(stageHeight)}px`;
    }

    stage.innerHTML = '';

    const page = await pdfDocument.getPage(currentPage);
    const viewport = page.getViewport({ scale });
    const pageCard = document.createElement('section');
    pageCard.className = 'ebook-reader-page';

    const pageMeta = document.createElement('div');
    pageMeta.className = 'ebook-reader-page-meta';
    pageMeta.textContent = `Page ${currentPage}`;

    const canvas = document.createElement('canvas');
    canvas.className = 'ebook-reader-canvas';
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);

    const context = canvas.getContext('2d', { alpha: false });
    await page.render({
      canvasContext: context,
      viewport,
    }).promise;

    pageCard.appendChild(pageMeta);
    pageCard.appendChild(canvas);
    stage.appendChild(pageCard);
    stage.style.minHeight = '';
    updateControls();
  };

  prevButton.addEventListener('click', async () => {
    if (!pdfDocument || currentPage <= 1) {
      return;
    }

    currentPage -= 1;
    pageLabel.textContent = 'Loading page...';
    await renderCurrentPage();
  });

  nextButton.addEventListener('click', async () => {
    if (!pdfDocument || currentPage >= pdfDocument.numPages) {
      return;
    }

    currentPage += 1;
    pageLabel.textContent = 'Loading page...';
    await renderCurrentPage();
  });

  zoomOutButton.addEventListener('click', async () => {
    scale = Math.max(MIN_SCALE, +(scale - SCALE_STEP).toFixed(2));
    pageLabel.textContent = 'Refreshing page...';
    await renderCurrentPage();
  });

  zoomInButton.addEventListener('click', async () => {
    scale = Math.min(MAX_SCALE, +(scale + SCALE_STEP).toFixed(2));
    pageLabel.textContent = 'Refreshing page...';
    await renderCurrentPage();
  });

  try {
    const task = pdfjsLib.getDocument({
      url: pdfUrl,
      withCredentials: true,
    });
    pdfDocument = await task.promise;
    loading.remove();
    updateControls();
    await renderCurrentPage();
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

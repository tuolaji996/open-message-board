(function () {
  const form = document.querySelector('[data-human-form]') || document.querySelector('#postForm');
  const submitButton = form?.querySelector('[data-submit-button]') || document.querySelector('#submitButton');
  const fileInput = document.querySelector('#imageInput');
  const dropzone = document.querySelector('#imageDropzone');
  const preview = document.querySelector('#imagePreview');
  const uploadMeta = document.querySelector('#uploadMeta');
  const slider = form?.querySelector('.slider-check') || document.querySelector('#botSlider');
  const botVerified = form?.querySelector('[name="bot_verified"]') || document.querySelector('#botVerified');
  const sliderStatus = slider?.querySelector('.slider-status') || document.querySelector('#sliderStatus');
  const readyText = submitButton?.dataset.readyText || submitButton?.textContent || '发布';
  const waitingText = submitButton?.dataset.waitingText || '完成验证后发布';
  const pendingText = submitButton?.dataset.pendingText || '正在提交...';

  let selectedFiles = [];
  let humanVerified = false;
  const canSyncFileInput = typeof window.DataTransfer === 'function';

  document.querySelectorAll('input[name="client_timezone"]').forEach((input) => {
    try {
      input.value = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (error) {
      input.value = '';
    }
  });
  document.querySelectorAll('input[name="browser_language"]').forEach((input) => {
    const languages = Array.isArray(navigator.languages) ? navigator.languages : [navigator.language];
    input.value = languages.filter(Boolean).join(', ').slice(0, 120);
  });

  function setHumanVerified(value) {
    humanVerified = value;
    if (botVerified) botVerified.value = value ? '1' : '0';
    if (submitButton) {
      submitButton.disabled = !value;
      submitButton.textContent = value ? readyText : waitingText;
    }
  }

  window.onHumanVerified = () => setHumanVerified(true);
  window.onHumanExpired = () => setHumanVerified(false);

  function syncFiles() {
    if (!fileInput) return;
    if (canSyncFileInput) {
      const transfer = new DataTransfer();
      selectedFiles.forEach((file) => transfer.items.add(file));
      fileInput.files = transfer.files;
    }
    if (uploadMeta) {
      uploadMeta.textContent = selectedFiles.length
        ? `${selectedFiles.length} 个附件已准备上传`
        : '最多 8 张，单张 5MB，支持 PNG/JPG/GIF/WebP';
    }
  }

  function renderPreview() {
    if (!preview) return;
    preview.replaceChildren();
    selectedFiles.forEach((file, index) => {
      const card = document.createElement('div');
      card.className = 'preview-card';
      const img = document.createElement('img');
      img.alt = file.name;
      img.src = URL.createObjectURL(file);
      img.onload = () => URL.revokeObjectURL(img.src);
      const info = document.createElement('div');
      info.className = 'preview-info';
      const fileName = document.createElement('strong');
      fileName.textContent = file.name;
      const fileMeta = document.createElement('span');
      fileMeta.textContent = `${(file.size / 1024 / 1024).toFixed(2)} MB · 可添加说明后发布`;
      info.append(fileName, fileMeta);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'preview-remove';
      remove.textContent = '×';
      remove.setAttribute('aria-label', `移除 ${file.name}`);
      remove.addEventListener('click', () => {
        selectedFiles.splice(index, 1);
        syncFiles();
        renderPreview();
      });
      card.append(img, info, remove);
      preview.appendChild(card);
    });
  }

  function addFiles(files) {
    const accepted = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const next = [...selectedFiles];
    const rejected = [];
    Array.from(files).forEach((file) => {
      if (!accepted.includes(file.type)) {
        rejected.push(`${file.name} 格式不支持`);
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        rejected.push(`${file.name} 超过 5MB`);
        return;
      }
      if (next.length >= 8) {
        rejected.push('最多只能上传 8 张图片');
        return;
      }
      const duplicate = next.some((item) => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified);
      if (!duplicate) next.push(file);
    });
    selectedFiles = next;
    syncFiles();
    renderPreview();
    if (uploadMeta && rejected.length > 0) {
      uploadMeta.textContent = rejected.slice(0, 2).join('；');
    }
  }

  if (fileInput && dropzone) {
    fileInput.addEventListener('change', () => addFiles(fileInput.files));
    dropzone.addEventListener('click', (event) => {
      if (event.target !== fileInput) fileInput.click();
    });
    dropzone.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        fileInput.click();
      }
    });
    ['dragenter', 'dragover'].forEach((name) => {
      dropzone.addEventListener(name, (event) => {
        event.preventDefault();
        dropzone.classList.add('is-dragging');
      });
    });
    ['dragleave', 'drop'].forEach((name) => {
      dropzone.addEventListener(name, (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
      });
    });
    dropzone.addEventListener('drop', (event) => addFiles(event.dataTransfer.files));
  }

  if (slider) {
    const thumb = slider.querySelector('.slider-thumb');
    const fill = slider.querySelector('.slider-fill');
    const track = slider.querySelector('.slider-track');
    let dragging = false;

    function completeSlider() {
      const rect = track.getBoundingClientRect();
      const max = rect.width - thumb.offsetWidth - 6;
      thumb.style.transform = `translateX(${Math.max(0, max)}px)`;
      fill.style.width = '100%';
      slider.classList.add('is-complete');
      if (sliderStatus) sliderStatus.textContent = '验证完成';
      thumb.textContent = '✓';
      dragging = false;
      setHumanVerified(true);
    }

    function setProgress(clientX) {
      const rect = track.getBoundingClientRect();
      const max = rect.width - thumb.offsetWidth - 6;
      const x = Math.max(0, Math.min(max, clientX - rect.left - thumb.offsetWidth / 2));
      const pct = max > 0 ? x / max : 0;
      thumb.style.transform = `translateX(${x}px)`;
      fill.style.width = `${Math.min(100, pct * 100)}%`;
      if (pct > 0.94) {
        completeSlider();
      }
    }

    thumb.addEventListener('pointerdown', (event) => {
      if (slider.classList.contains('is-complete')) return;
      dragging = true;
      thumb.setPointerCapture(event.pointerId);
    });
    thumb.addEventListener('pointermove', (event) => {
      if (dragging) setProgress(event.clientX);
    });
    thumb.addEventListener('pointerup', () => {
      if (!slider.classList.contains('is-complete')) {
        thumb.style.transform = 'translateX(0)';
        fill.style.width = '0';
      }
      dragging = false;
    });
    thumb.addEventListener('keydown', (event) => {
      if (slider.classList.contains('is-complete')) return;
      if (event.key === 'Enter' || event.key === ' ' || event.key === 'End') {
        event.preventDefault();
        completeSlider();
      }
    });
  }

  if (form) {
    form.addEventListener('submit', (event) => {
      if (!humanVerified) {
        event.preventDefault();
        const verifier = document.querySelector('.turnstile-card, .slider-check');
        verifier?.classList.add('needs-attention');
        setTimeout(() => verifier?.classList.remove('needs-attention'), 900);
      } else if (submitButton) {
        submitButton.textContent = pendingText;
        submitButton.disabled = true;
      }
    });
  }

  const commentForm = document.querySelector('#commentForm');
  if (commentForm) {
    const parentInput = commentForm.querySelector('#commentParentId');
    const replyTarget = commentForm.querySelector('#replyTarget');
    const replyTargetText = replyTarget?.querySelector('span');
    const textarea = commentForm.querySelector('textarea[name="body"]');
    document.querySelectorAll('.comment-reply').forEach((button) => {
      button.addEventListener('click', () => {
        if (parentInput) parentInput.value = button.dataset.replyId || '';
        if (replyTarget && replyTargetText) {
          replyTarget.hidden = false;
          replyTargetText.textContent = `正在回复 @${button.dataset.replyAuthor || 'Anonymous'}`;
        }
        commentForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        textarea?.focus({ preventScroll: true });
      });
    });
    document.querySelector('#cancelReply')?.addEventListener('click', () => {
      if (parentInput) parentInput.value = '';
      if (replyTarget) replyTarget.hidden = true;
    });
  }

  setHumanVerified(false);
})();

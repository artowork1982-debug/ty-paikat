/**
 * Frontend Modal JavaScript - Tab-based Redesign
 * Luo slide-over modal työpaikan infopaketin näyttämiseen
 * Tuki: Tabit, Video, Kuvagalleria, Lightbox, Mobiiliresponsiivisuus
 */
(function() {
    'use strict';

    let currentJobId = null;
    let currentLang = null;
    let modalElement = null;
    let lightboxElement = null;
    let i18n = {};
    let currentTab = 'general';

    /**
     * Alusta modal
     */
    function init() {
        // Käytä event delegation job-linkkien klikkauksiin
        document.addEventListener('click', function(e) {
            const jobLink = e.target.closest('a[data-job-id]');
            if (jobLink) {
                e.preventDefault();
                const jobId = jobLink.getAttribute('data-job-id');
                if (jobId) {
                    openModal(jobId);
                }
            }
        });

        // ESC-näppäin sulkee modalin tai lightboxin
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (lightboxElement && lightboxElement.classList.contains('is-open')) {
                    closeLightbox();
                } else if (modalElement) {
                    closeModal();
                }
            }
        });
    }

    /**
     * Avaa modal
     */
    function openModal(jobId, lang) {
        currentJobId = jobId;
        currentLang = lang || (window.mapModalConfig ? window.mapModalConfig.lang : 'fi');
        currentTab = 'general'; // Reset to first tab

        // Luo modal DOM jos ei ole vielä
        if (!modalElement) {
            createModalDOM();
        }

        // Näytä modal
        modalElement.classList.add('is-open');
        document.body.style.overflow = 'hidden';

        // Lataa data
        loadJobData(currentJobId, currentLang);
    }

    /**
     * Sulje modal
     */
    function closeModal() {
        if (!modalElement) return;

        modalElement.classList.remove('is-open');
        document.body.style.overflow = '';

        // Odota transition ennen sisällön tyhjennystä
        setTimeout(function() {
            const content = modalElement.querySelector('.map-modal__content');
            if (content) {
                content.innerHTML = '';
            }
        }, 300);
    }

    /**
     * Luo modal DOM-rakenne
     */
    function createModalDOM() {
        modalElement = document.createElement('div');
        modalElement.className = 'map-modal-overlay';
        modalElement.innerHTML = `
            <div class="map-modal-panel">
                <div class="map-modal__content"></div>
            </div>
        `;

        document.body.appendChild(modalElement);

        // Overlay-klikkaus sulkee
        modalElement.addEventListener('click', function(e) {
            if (e.target.classList.contains('map-modal-overlay')) {
                closeModal();
            }
        });
    }

    /**
     * Lataa työpaikan data REST API:sta
     */
    function loadJobData(jobId, lang) {
        const content = modalElement.querySelector('.map-modal__content');
        if (!content) return;

        // Näytä loading-spinner
        i18n = window.mapModalConfig && window.mapModalConfig.i18n ? window.mapModalConfig.i18n : {};
        content.innerHTML = `
            <div class="map-modal__loading">
                <div class="map-spinner"></div>
                <p>${i18n['modal.loading'] || 'Ladataan...'}</p>
            </div>
        `;

        // Hae data
        const restUrl = window.mapModalConfig ? window.mapModalConfig.restUrl : '/wp-json/map/v1';
        const url = `${restUrl}/job-info/${jobId}?lang=${lang}`;

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                i18n = data.i18n || i18n;
                renderJobInfo(data);
            })
            .catch(error => {
                console.error('Error loading job info:', error);
                content.innerHTML = `
                    <div class="map-modal__error">
                        <p>${i18n['modal.load_error'] || 'Tietojen lataaminen epäonnistui.'}</p>
                        <button type="button" class="map-modal__retry">
                            ${i18n['modal.close'] || 'Sulje'}
                        </button>
                    </div>
                `;
                
                const retryBtn = content.querySelector('.map-modal__retry');
                if (retryBtn) {
                    retryBtn.addEventListener('click', closeModal);
                }
            });
    }

    /**
     * Renderöi työpaikan tiedot tab-pohjaisena
     */
    function renderJobInfo(data) {
        const content = modalElement.querySelector('.map-modal__content');
        if (!content) return;

        const pkg = data.infopackage;
        const hasMedia = pkg && ((pkg.video_url && pkg.video_url.trim()) || (pkg.gallery && pkg.gallery.length > 0));
        const hasQuestions = pkg && pkg.questions && pkg.questions.length > 0;
        const showTabs = hasMedia || hasQuestions;

        let html = '';

        // Top bar: sulkemisnappi ja kielivalitsin
        html += '<div class="map-modal__topbar">';
        html += '<button type="button" class="map-modal__close" aria-label="Close">&times;</button>';
        
        // Kielivalitsin
        if (pkg && pkg.available_languages) {
            const availableLangs = Object.keys(pkg.available_languages).filter(lang => 
                pkg.available_languages[lang]
            );
            
            if (availableLangs.length > 1) {
                html += '<div class="map-modal__lang-switcher">';
                availableLangs.forEach(lang => {
                    const isActive = lang === data.lang;
                    html += `<button type="button" class="map-lang-btn ${isActive ? 'is-active' : ''}" data-lang="${lang}">${lang.toUpperCase()}</button>`;
                });
                html += '</div>';
            }
        }
        
        html += '</div>';

        // Otsikko
        html += `<h2 class="map-modal__title">${escapeHtml(data.title)}</h2>`;

        // Excerpt
        if (data.excerpt) {
            html += `<div class="map-modal__excerpt">${escapeHtml(data.excerpt)}</div>`;
        }

        // Tabit (jos tarvitaan)
        if (showTabs) {
            html += '<div class="map-modal__tabs">';
            html += `<button type="button" class="map-tab-btn is-active" data-tab="general">${i18n['tab.general'] || 'Yleistä'}</button>`;
            if (hasMedia) {
                html += `<button type="button" class="map-tab-btn" data-tab="media">${i18n['tab.videos'] || 'Videot'}</button>`;
            }
            if (hasQuestions) {
                html += `<button type="button" class="map-tab-btn" data-tab="questions">${i18n['tab.questions'] || 'Kysymykset'}</button>`;
            }
            html += '</div>';
        }

        // Tab-sisältö: Yleistä
        html += `<div class="map-tab-content" data-tab-content="general">`;
        
        // Työn kuvaus (laura:description)
        // Note: Server sanitizes with wp_kses_post (WordPress standard for post content)
        // This is the same sanitization used for all WordPress post content and is safe to render as HTML
        if (data.description) {
            html += '<div class="map-modal__job-description">' + data.description + '</div>';
        }
        
        if (pkg) {
            // Highlights
            if (pkg.highlights && pkg.highlights.length > 0) {
                html += '<div class="map-modal__highlights">';
                pkg.highlights.forEach(highlight => {
                    html += `<span class="map-highlight-pill">${escapeHtml(highlight)}</span>`;
                });
                html += '</div>';
            }

            // Intro
            if (pkg.intro) {
                html += `<div class="map-modal__intro">${escapeHtml(pkg.intro)}</div>`;
            }

            // Yhteyshenkilö
            if (pkg.contact && (pkg.contact.name || pkg.contact.email || pkg.contact.phone)) {
                html += `<h3 class="map-modal__section-heading">${i18n['modal.contact_heading'] || 'Yhteyshenkilö'}</h3>`;
                html += '<div class="map-modal__contact">';
                if (pkg.contact.name) {
                    html += `<p><strong>👤 ${escapeHtml(pkg.contact.name)}</strong></p>`;
                }
                if (pkg.contact.email) {
                    html += `<p>📧 <a href="mailto:${escapeHtml(pkg.contact.email)}">${escapeHtml(pkg.contact.email)}</a></p>`;
                }
                if (pkg.contact.phone) {
                    html += `<p>📱 ${escapeHtml(pkg.contact.phone)}</p>`;
                }
                html += '</div>';
            }
        }
        html += '</div>';

        // Tab-sisältö: Media
        if (hasMedia) {
            html += `<div class="map-tab-content" data-tab-content="media" style="display:none;">`;
            
            // Video
            if (pkg.video_url && pkg.video_url.trim()) {
                const embedUrl = parseVideoUrl(pkg.video_url);
                if (embedUrl) {
                    html += '<div class="map-modal__video-wrapper">';
                    html += `<iframe src="${escapeHtml(embedUrl)}" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>`;
                    html += '</div>';
                }
            }

            // Galleria
            if (pkg.gallery && pkg.gallery.length > 0) {
                html += '<div class="map-modal__gallery">';
                pkg.gallery.forEach((image, index) => {
                    html += `<div class="map-gallery-item" data-index="${index}">`;
                    html += `<img src="${escapeHtml(image.thumb)}" alt="" loading="lazy" />`;
                    html += '</div>';
                });
                html += '</div>';
            }

            html += '</div>';
        }

        // Tab-sisältö: Kysymykset
        if (hasQuestions) {
            html += `<div class="map-tab-content" data-tab-content="questions" style="display:none;">`;
            html += '<div class="map-modal__questions">';
            pkg.questions.forEach((q, index) => {
                html += renderQuestion(q, index);
            });
            html += '</div>';
            html += '</div>';
        }

        // CTA-nappi (sticky)
        if (data.apply_url) {
            html += `<div class="map-modal__cta">
                <a href="${escapeHtml(data.apply_url)}" target="_blank" rel="noopener" class="map-cta-button">
                    ${i18n['modal.cta_apply'] || 'Siirry hakemaan →'}
                </a>
            </div>`;
        }

        content.innerHTML = html;

        // Event listenerit
        attachEventListeners(data);
    }

    /**
     * Kiinnitä event listenerit modaliin
     */
    function attachEventListeners(data) {
        const content = modalElement.querySelector('.map-modal__content');

        // Sulkemisnappi
        const closeBtn = content.querySelector('.map-modal__close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }

        // Kielivalitsimet
        const langButtons = content.querySelectorAll('.map-lang-btn');
        langButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const newLang = this.getAttribute('data-lang');
                if (newLang && newLang !== currentLang) {
                    openModal(currentJobId, newLang);
                }
            });
        });

        // Tab-napit
        const tabButtons = content.querySelectorAll('.map-tab-btn');
        tabButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');
                switchTab(targetTab);
            });
        });

        // Galleria-kuvien klikkaukset (lightbox)
        const galleryItems = content.querySelectorAll('.map-gallery-item');
        if (data.infopackage && data.infopackage.gallery) {
            galleryItems.forEach(item => {
                item.addEventListener('click', function() {
                    const index = parseInt(this.getAttribute('data-index'), 10);
                    openLightbox(data.infopackage.gallery, index);
                });
            });
        }

        // Yes/No pill buttons
        const pillButtons = content.querySelectorAll('.map-pill-button');
        pillButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const siblings = this.parentElement.querySelectorAll('.map-pill-button');
                siblings.forEach(s => s.classList.remove('is-selected'));
                this.classList.add('is-selected');
                
                // Tarkista palaute
                const questionDiv = this.closest('.map-question');
                checkUnsuitableFeedback(questionDiv, this.getAttribute('data-value'));
            });
        });

        // Scale radio buttons
        const scaleInputs = content.querySelectorAll('.map-question__scale input[type="radio"]');
        scaleInputs.forEach(input => {
            input.addEventListener('change', function() {
                const questionDiv = this.closest('.map-question');
                checkUnsuitableFeedback(questionDiv, this.value);
            });
        });

        // Select dropdown
        const selectInputs = content.querySelectorAll('.map-question__select');
        selectInputs.forEach(select => {
            select.addEventListener('change', function() {
                const questionDiv = this.closest('.map-question');
                checkUnsuitableFeedback(questionDiv, this.value);
            });
        });
    }

    /**
     * Vaihda aktiivista tabia
     */
    function switchTab(tabName) {
        currentTab = tabName;
        
        const content = modalElement.querySelector('.map-modal__content');
        
        // Päivitä tab-napit
        const tabButtons = content.querySelectorAll('.map-tab-btn');
        tabButtons.forEach(btn => {
            if (btn.getAttribute('data-tab') === tabName) {
                btn.classList.add('is-active');
            } else {
                btn.classList.remove('is-active');
            }
        });

        // Päivitä tab-sisällöt
        const tabContents = content.querySelectorAll('.map-tab-content');
        tabContents.forEach(tc => {
            if (tc.getAttribute('data-tab-content') === tabName) {
                tc.style.display = 'block';
            } else {
                tc.style.display = 'none';
            }
        });
    }

    /**
     * Avaa lightbox kuvagallerialle
     */
    function openLightbox(gallery, startIndex) {
        if (!lightboxElement) {
            createLightboxDOM();
        }

        const image = lightboxElement.querySelector('.map-lightbox__image');
        const counter = lightboxElement.querySelector('.map-lightbox__counter');
        
        let currentIndex = startIndex;

        function showImage(index) {
            currentIndex = index;
            image.src = gallery[index].url;
            counter.textContent = `${index + 1} / ${gallery.length}`;
        }

        // Navigointi
        const prevBtn = lightboxElement.querySelector('.map-lightbox__prev');
        const nextBtn = lightboxElement.querySelector('.map-lightbox__next');

        prevBtn.onclick = function() {
            const newIndex = (currentIndex - 1 + gallery.length) % gallery.length;
            showImage(newIndex);
        };

        nextBtn.onclick = function() {
            const newIndex = (currentIndex + 1) % gallery.length;
            showImage(newIndex);
        };

        showImage(startIndex);
        lightboxElement.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    /**
     * Sulje lightbox
     */
    function closeLightbox() {
        if (!lightboxElement) return;
        lightboxElement.classList.remove('is-open');
        // Don't restore body overflow here - modal is still open
    }

    /**
     * Luo lightbox DOM
     */
    function createLightboxDOM() {
        lightboxElement = document.createElement('div');
        lightboxElement.className = 'map-lightbox-overlay';
        lightboxElement.innerHTML = `
            <button type="button" class="map-lightbox__close" aria-label="Close">&times;</button>
            <button type="button" class="map-lightbox__prev" aria-label="Previous">‹</button>
            <button type="button" class="map-lightbox__next" aria-label="Next">›</button>
            <div class="map-lightbox__content">
                <img class="map-lightbox__image" src="" alt="" />
                <div class="map-lightbox__counter">1 / 1</div>
            </div>
        `;

        document.body.appendChild(lightboxElement);

        // Sulkemisnappi
        const closeBtn = lightboxElement.querySelector('.map-lightbox__close');
        closeBtn.addEventListener('click', closeLightbox);

        // Overlay-klikkaus sulkee
        lightboxElement.addEventListener('click', function(e) {
            if (e.target.classList.contains('map-lightbox-overlay')) {
                closeLightbox();
            }
        });
    }

    /**
     * Parsii video URL:n embed-muotoon
     * Validates and converts YouTube/Vimeo URLs to embed format
     */
    function parseVideoUrl(url) {
        if (!url) return null;

        url = url.trim();

        // YouTube - validate and extract video ID
        let match = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        if (match) {
            return `https://www.youtube.com/embed/${match[1]}`;
        }

        // Vimeo - validate and extract video ID
        match = url.match(/vimeo\.com\/(\d+)/);
        if (match) {
            return `https://player.vimeo.com/video/${match[1]}`;
        }

        // Validate if already in embed format
        if (url.match(/^https:\/\/(www\.youtube\.com\/embed\/[a-zA-Z0-9_-]{11}|player\.vimeo\.com\/video\/\d+)$/)) {
            return url;
        }

        return null;
    }

    /**
     * Renderöi yksittäinen kysymys
     */
    function renderQuestion(question, index) {
        let html = '<div class="map-question">';
        
        // Kysymysteksti
        html += `<label class="map-question__label">
            ${escapeHtml(question.question)}
            ${question.required ? `<span class="map-required">*</span>` : ''}
        </label>`;

        // Tyyppikohtainen renderöinti
        switch (question.type) {
            case 'text':
                html += `<textarea class="map-question__textarea" placeholder="${i18n['question.text_placeholder'] || 'Kirjoita vastauksesi tähän'}" ${question.required ? 'required' : ''}></textarea>`;
                break;

            case 'yesno':
                html += '<div class="map-question__pills">';
                html += `<button type="button" class="map-pill-button" data-value="yes">${i18n['question.yes'] || 'Kyllä'}</button>`;
                html += `<button type="button" class="map-pill-button" data-value="no">${i18n['question.no'] || 'Ei'}</button>`;
                html += '</div>';
                break;

            case 'scale':
                html += '<div class="map-question__scale">';
                for (let i = 1; i <= 5; i++) {
                    html += `<label class="map-scale-option">
                        <input type="radio" name="question_${index}" value="${i}" ${question.required ? 'required' : ''}>
                        <span>${i}</span>
                    </label>`;
                }
                html += '</div>';
                break;

            case 'select':
                html += `<select class="map-question__select" ${question.required ? 'required' : ''}>`;
                html += `<option value="">${i18n['question.select_placeholder'] || 'Valitse...'}</option>`;
                if (question.options) {
                    const options = question.options.split('\n');
                    options.forEach(opt => {
                        const trimmed = opt.trim();
                        if (trimmed) {
                            html += `<option value="${escapeHtml(trimmed)}">${escapeHtml(trimmed)}</option>`;
                        }
                    });
                }
                html += '</select>';
                break;

            case 'info':
                // Pelkkä teksti, ei input-kenttää
                break;

            default:
                html += `<input type="text" class="map-question__input" ${question.required ? 'required' : ''}>`;
        }

        // Palaute-banneri placeholder (piilotettu oletuksena)
        // Null-suojaukset varmistavat yhteensopivuuden vanhan datan kanssa
        const unsuitableValue = question.unsuitable_value || '';
        const unsuitableFeedback = question.unsuitable_feedback || '';
        
        if (unsuitableValue && unsuitableFeedback) {
            html += `<div class="map-question__feedback" style="display:none;" data-unsuitable-values="${escapeHtml(unsuitableValue)}">
                <div class="map-feedback-banner">
                    <span class="map-feedback-icon">💡</span>
                    <div class="map-feedback-text">
                        <strong>${i18n['feedback.heading'] || 'Huomio'}</strong>
                        <p>${escapeHtml(unsuitableFeedback)}</p>
                    </div>
                </div>
            </div>`;
        } else if (unsuitableValue) {
            // Käytä oletuspalautetta
            html += `<div class="map-question__feedback" style="display:none;" data-unsuitable-values="${escapeHtml(unsuitableValue)}">
                <div class="map-feedback-banner">
                    <span class="map-feedback-icon">💡</span>
                    <div class="map-feedback-text">
                        <strong>${i18n['feedback.heading'] || 'Huomio'}</strong>
                        <p>${i18n['feedback.unsuitable_default'] || 'Tämä tehtävä ei välttämättä vastaa kaikkia toiveitasi, mutta voit silti jatkaa hakemista!'}</p>
                    </div>
                </div>
            </div>`;
        }

        html += '</div>';
        return html;
    }

    /**
     * Tarkista epäsopiva palaute ja näytä banneri tarvittaessa
     */
    function checkUnsuitableFeedback(questionDiv, selectedValue) {
        if (!questionDiv) return;
        const feedbackDiv = questionDiv.querySelector('.map-question__feedback');
        if (!feedbackDiv) return;
        
        const unsuitableValuesAttr = feedbackDiv.getAttribute('data-unsuitable-values');
        if (!unsuitableValuesAttr) return;
        
        const unsuitableValues = unsuitableValuesAttr
            .split(',')
            .map(v => v.trim().toLowerCase());
        
        const selectedValueLower = String(selectedValue || '').toLowerCase();
        
        if (unsuitableValues.includes(selectedValueLower)) {
            feedbackDiv.style.display = 'block';
        } else {
            feedbackDiv.style.display = 'none';
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    // Alusta kun DOM on valmis
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

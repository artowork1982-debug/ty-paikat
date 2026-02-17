/**
 * Frontend Modal JavaScript
 * Luo slide-over modal työpaikan infopaketin näyttämiseen
 */
(function() {
    'use strict';

    let currentJobId = null;
    let currentLang = null;
    let modalElement = null;
    let i18n = {};

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

        // ESC-näppäin sulkee modalin
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalElement) {
                closeModal();
            }
        });
    }

    /**
     * Avaa modal
     */
    function openModal(jobId, lang) {
        currentJobId = jobId;
        currentLang = lang || (window.mapModalConfig ? window.mapModalConfig.lang : 'fi');

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
                        <button type="button" class="map-modal__retry" onclick="location.reload()">
                            ${i18n['modal.close'] || 'Sulje'}
                        </button>
                    </div>
                `;
            });
    }

    /**
     * Renderöi työpaikan tiedot
     */
    function renderJobInfo(data) {
        const content = modalElement.querySelector('.map-modal__content');
        if (!content) return;

        let html = '';

        // Top bar: sulkemisnappi ja kielivalitsin
        html += '<div class="map-modal__topbar">';
        html += `<button type="button" class="map-modal__close" onclick="this.closest('.map-modal-overlay').dispatchEvent(new CustomEvent('closeModal'))">&times;</button>`;
        
        // Kielivalitsin (jos useita kieliä saatavilla)
        if (data.infopackage && data.infopackage.available_languages) {
            const availableLangs = Object.keys(data.infopackage.available_languages).filter(lang => 
                data.infopackage.available_languages[lang]
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

        // Infopaketti
        if (data.infopackage) {
            const pkg = data.infopackage;

            // Intro
            if (pkg.intro) {
                html += `<div class="map-modal__intro">${escapeHtml(pkg.intro)}</div>`;
            }

            // Highlights
            if (pkg.highlights && pkg.highlights.length > 0) {
                html += '<div class="map-modal__highlights">';
                pkg.highlights.forEach(highlight => {
                    html += `<span class="map-highlight-pill">${escapeHtml(highlight)}</span>`;
                });
                html += '</div>';
            }

            // Kysymykset
            if (pkg.questions && pkg.questions.length > 0) {
                html += `<h3 class="map-modal__section-heading">${i18n['modal.questions_heading'] || 'Kysymykset'}</h3>`;
                html += '<div class="map-modal__questions">';
                pkg.questions.forEach((q, index) => {
                    html += renderQuestion(q, index);
                });
                html += '</div>';
            }

            // Yhteyshenkilö
            if (pkg.contact && (pkg.contact.name || pkg.contact.email || pkg.contact.phone)) {
                html += `<h3 class="map-modal__section-heading">${i18n['modal.contact_heading'] || 'Yhteyshenkilö'}</h3>`;
                html += '<div class="map-modal__contact">';
                if (pkg.contact.name) {
                    html += `<p><strong>${escapeHtml(pkg.contact.name)}</strong></p>`;
                }
                if (pkg.contact.email) {
                    html += `<p><a href="mailto:${escapeHtml(pkg.contact.email)}">${escapeHtml(pkg.contact.email)}</a></p>`;
                }
                if (pkg.contact.phone) {
                    html += `<p>${escapeHtml(pkg.contact.phone)}</p>`;
                }
                html += '</div>';
            }
        }

        // CTA-nappi
        if (data.apply_url) {
            html += `<div class="map-modal__cta">
                <a href="${escapeHtml(data.apply_url)}" target="_blank" rel="noopener" class="map-cta-button">
                    ${i18n['modal.cta_apply'] || 'Siirry hakemaan →'}
                </a>
            </div>`;
        }

        content.innerHTML = html;

        // Lisää event listenerit kielivalitsimelle
        const langButtons = content.querySelectorAll('.map-lang-btn');
        langButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const newLang = this.getAttribute('data-lang');
                if (newLang && newLang !== currentLang) {
                    openModal(currentJobId, newLang);
                }
            });
        });

        // Lisää close-event listener
        modalElement.addEventListener('closeModal', closeModal);
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

        html += '</div>';
        return html;
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

    // Globaali funktio modalin sulkemiseen (käytetään close-napissa)
    window.mapCloseModal = closeModal;

})();

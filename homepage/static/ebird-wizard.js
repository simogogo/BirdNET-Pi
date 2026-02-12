/**
 * BirdNET-Pi eBird Export Wizard
 * Interfaccia guidata step-by-step per l'export verso eBird
 */

class eBirdExportWizard {
    constructor() {
        this.currentStep = 0;
        this.selectedDate = null;
        this.detectionsByHour = {};
        this.exportData = {
            species: [],
            location: {},
            protocol: 'Stationary',
            observers: 1
        };
        this.init();
    }

    init() {
        this.addExportButton();
        this.loadLocationConfig();
    }

    /**
     * Aggiunge il pulsante di apertura wizard
     */
    addExportButton() {
        // Leggi la data dal parametro GET
        const urlParams = new URLSearchParams(window.location.search);
        const dateParam = urlParams.get('date') || urlParams.get('Date') || urlParams.get('day');
        
        // Se non c'è una data specifica, non mostrare il pulsante
        if (!dateParam) {
            console.log('eBird Export: nessuna data specifica trovata nei parametri URL, pulsante non mostrato');
            return;
        }
        
        // Salva la data
        this.selectedDate = dateParam;
        
        const recordingsHeader = document.querySelector('.recordings-header') || 
                                document.querySelector('#recordings h2')?.parentElement ||
                                document.querySelector('.play');
        
        if (!recordingsHeader) {
            console.error('Impossibile trovare il container per il pulsante');
            return;
        }

        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'ebird-wizard-trigger';
        buttonContainer.innerHTML = `
            <button id="start-ebird-wizard" class="btn-ebird-primary">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="12" y1="18" x2="12" y2="12"></line>
                    <line x1="9" y1="15" x2="15" y2="15"></line>
                </svg>
                Esporta su eBird (${this.formatDate(this.selectedDate)})
            </button>
        `;

        recordingsHeader.appendChild(buttonContainer);

        document.getElementById('start-ebird-wizard').addEventListener('click', () => {
            this.startWizard();
        });
    }

    /**
     * Carica configurazione localizzazione
     */
    async loadLocationConfig() {
        try {
            // Prova a caricare da API
            const response = await fetch('/ebird-api.php?action=get_location');
            if (response.ok) {
                const data = await response.json();
                
                // Salva in exportData con fallback a localStorage
                this.exportData.location = {
                    latitude: data.latitude || localStorage.getItem('birdnet_latitude') || '',
                    longitude: data.longitude || localStorage.getItem('birdnet_longitude') || '',
                    locality: data.locality || localStorage.getItem('birdnet_locality') || 'BirdNET-Pi Station',
                    stateProvince: data.stateProvince || localStorage.getItem('birdnet_state_province') || '',
                    countryCode: data.countryCode || localStorage.getItem('birdnet_country_code') || ''
                };
                
                // Salva anche in localStorage per la prossima volta
                if (data.latitude) localStorage.setItem('birdnet_latitude', data.latitude);
                if (data.longitude) localStorage.setItem('birdnet_longitude', data.longitude);
                if (data.locality) localStorage.setItem('birdnet_locality', data.locality);
                if (data.stateProvince) localStorage.setItem('birdnet_state_province', data.stateProvince);
                if (data.countryCode) localStorage.setItem('birdnet_country_code', data.countryCode);
                
                return;
            }
        } catch (error) {
            console.log('API non disponibile, uso localStorage:', error);
        }
        
        // Fallback a localStorage
        this.exportData.location = {
            latitude: localStorage.getItem('birdnet_latitude') || '',
            longitude: localStorage.getItem('birdnet_longitude') || '',
            locality: localStorage.getItem('birdnet_locality') || 'BirdNET-Pi Station',
            stateProvince: localStorage.getItem('birdnet_state_province') || '',
            countryCode: localStorage.getItem('birdnet_country_code') || ''
        };
    }

    /**
     * Avvia il wizard
     */
    async startWizard() {
        this.currentStep = 0;
        this.createWizardContainer();
        this.showStep(0);
    }

    /**
     * Crea il container del wizard
     */
    createWizardContainer() {
        // Rimuovi wizard precedente se esiste
        const existing = document.getElementById('ebird-wizard');
        if (existing) existing.remove();

        const wizard = document.createElement('div');
        wizard.id = 'ebird-wizard';
        wizard.className = 'ebird-wizard-overlay';
        wizard.innerHTML = `
            <div class="wizard-modal">
                <div class="wizard-header">
                    <h2>Export eBird - Wizard Guidato</h2>
                    <button class="wizard-close" onclick="document.getElementById('ebird-wizard').remove()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
                
                <div class="wizard-progress">
                    <div class="progress-step" data-step="0">
                        <div class="step-number">1</div>
                        <div class="step-label">Revisione Specie</div>
                    </div>
                    <div class="progress-step" data-step="1">
                        <div class="step-number">2</div>
                        <div class="step-label">Configurazione</div>
                    </div>
                    <div class="progress-step" data-step="2">
                        <div class="step-number">3</div>
                        <div class="step-label">Riepilogo</div>
                    </div>
                </div>

                <div class="wizard-content" id="wizard-content">
                    <!-- Il contenuto verrà inserito qui dinamicamente -->
                </div>

                <div class="wizard-footer">
                    <button id="wizard-prev" class="btn-secondary" style="display: none;">
                        ← Indietro
                    </button>
                    <div class="wizard-footer-spacer"></div>
                    <button id="wizard-cancel" class="btn-secondary">
                        Annulla
                    </button>
                    <button id="wizard-next" class="btn-primary">
                        Avanti →
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(wizard);

        // Event listeners
        document.getElementById('wizard-prev').addEventListener('click', () => this.previousStep());
        document.getElementById('wizard-next').addEventListener('click', () => this.nextStep());
        document.getElementById('wizard-cancel').addEventListener('click', () => wizard.remove());
    }

    /**
     * Mostra uno step specifico
     */
    async showStep(step) {
        this.currentStep = step;
        this.updateProgressBar();
        this.updateNavigationButtons();

        const content = document.getElementById('wizard-content');
        content.innerHTML = '<div class="wizard-loading">Caricamento...</div>';

        switch(step) {
            case 0:
                await this.renderSpeciesReviewStep();
                break;
            case 1:
                this.renderConfigurationStep();
                break;
            case 2:
                this.renderSummaryStep();
                break;
        }
    }

    /**
     * STEP 1: Revisione specie per ora
     */
    async renderSpeciesReviewStep() {
        const content = document.getElementById('wizard-content');
        
        if (!this.selectedDate) {
            content.innerHTML = '<div class="error-message">Errore: nessuna data selezionata</div>';
            return;
        }

        // Carica le detection per la data selezionata
        this.detectionsByHour = await this.fetchDetectionsByDate(this.selectedDate);

        const hours = Object.keys(this.detectionsByHour).sort();

        content.innerHTML = `
            <div class="step-container">
                <h3>Revisiona le specie - ${this.formatDate(this.selectedDate)}</h3>
                <p class="step-description">
                    Per ogni fascia oraria, puoi rimuovere specie dall'export o modificare il conteggio delle detection.
                    Le specie con bassa confidenza sono evidenziate.
                </p>

                <div class="review-controls">
                    <label class="checkbox-label">
                        <input type="checkbox" id="auto-remove-low-confidence">
                        Rimuovi automaticamente specie con confidenza < 80%
                    </label>
                </div>

                <div class="hourly-review-container">
                    ${hours.map(hour => this.renderHourlyReview(hour)).join('')}
                </div>

                <div class="review-summary">
                    <div class="summary-box">
                        <div class="summary-number" id="total-species-count">0</div>
                        <div class="summary-label">Specie totali</div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-number" id="total-detections-count">0</div>
                        <div class="summary-label">Detection totali</div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-number" id="removed-count">0</div>
                        <div class="summary-label">Escluse</div>
                    </div>
                </div>
            </div>
        `;

        // Event listeners
        document.getElementById('auto-remove-low-confidence')?.addEventListener('change', (e) => {
            this.autoRemoveLowConfidence(e.target.checked);
        });
		
		this.handleDuplicateRemoval();
        this.updateReviewSummary();
    }

    /**
     * Renderizza la revisione per una fascia oraria
     */
    renderHourlyReview(hour) {
        const detections = this.detectionsByHour[hour];
        const hourLabel = this.formatHour(hour);

        return `
            <div class="hour-section" data-hour="${hour}">
                <div class="hour-header" onclick="this.parentElement.classList.toggle('collapsed')">
                    <div class="hour-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        ${hourLabel}
                    </div>
                    <div class="hour-stats">
                        ${detections.length} detection
                    </div>
                    <svg class="hour-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </div>
                
                <div class="hour-content">
                    <div class="species-list">
                        ${detections.map((detection, idx) => this.renderSpeciesCard(detection, hour, idx)).join('')}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Renderizza la card di una singola specie
     */
    renderSpeciesCard(detection, hour, index) {
        const confidenceClass = detection.confidence >= 0.9 ? 'high' : 
                               detection.confidence >= 0.75 ? 'medium' : 'low';
        
        const speciesId = `${hour}-${index}`;
        
        // Inizializza il conteggio se non presente
        if (!detection.count) {
            detection.count = 'X';
        }

        return `
            <div class="species-card ${detection.included !== false ? '' : 'excluded'}" 
                 data-species-id="${speciesId}"
                 data-hour="${hour}"
                 data-index="${index}">
                <div class="species-card-header">
                    <label class="checkbox-container">
                        <input type="checkbox" 
                               class="species-checkbox"
                               data-hour="${hour}"
                               data-index="${index}"
                               ${detection.included !== false ? 'checked' : ''}
                               onchange="window.ebirdWizard.toggleSpecies('${hour}', ${index}, this.checked)">
                        <span class="checkmark"></span>
                    </label>
                    
                    <div class="species-info">
                        <div class="species-name-common">${detection.common_name}</div>
                        <div class="species-name-scientific">${detection.scientific_name}</div>
                    </div>

                    <div class="species-count-input">
                        <label for="count-${hour}-${index}">N°</label>
                        <input type="text" 
                               id="count-${hour}-${index}"
                               class="count-input"
                               value="${detection.count}"
                               placeholder="X"
                               maxlength="5"
                               data-hour="${hour}"
                               data-index="${index}"
                               onchange="window.ebirdWizard.updateSpeciesCount('${hour}', ${index}, this.value)">
                    </div>

                    <div class="species-confidence confidence-${confidenceClass}">
                        ${(detection.confidence * 100).toFixed(0)}%
                    </div>
                </div>

                <div class="species-card-details">
                    <div class="detail-row">
                        <span class="detail-label">Ora rilevamento:</span>
                        <span class="detail-value">${detection.time}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">File audio:</span>
                        <span class="detail-value">
                            <button class="btn-play-audio" onclick="window.ebirdWizard.playAudio('/By_Date/${detection.scientific_name.replace(" ", "_")}/${detection.filename}')">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                </svg>
                                Ascolta
                            </button>
                        </span>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * STEP 3: Configurazione
     */
    renderConfigurationStep() {
        const content = document.getElementById('wizard-content');

        content.innerHTML = `
            <div class="step-container">
                <h3>Configurazione Checklist</h3>
                <p class="step-description">
                    Configura i dettagli della tua checklist eBird prima dell'export.
                </p>

                <div class="config-form">
                    <div class="form-section">
                        <h4>Localizzazione</h4>
                        
                        <div class="form-group">
                            <label for="location-name">Nome Località *</label>
                            <input type="text" 
                                   id="location-name" 
                                   class="form-control"
                                   value="${this.exportData.location.locality || ''}"
                                   placeholder="es: Giardino di casa, Milano"
                                   required>
                            <small>Il nome che apparirà su eBird</small>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="latitude">Latitudine *</label>
                                <input type="number" 
                                       id="latitude" 
                                       class="form-control"
                                       step="0.000001"
                                       value="${this.exportData.location.latitude || ''}"
                                       placeholder="45.4642"
                                       required>
                            </div>

                            <div class="form-group">
                                <label for="longitude">Longitudine *</label>
                                <input type="number" 
                                       id="longitude" 
                                       class="form-control"
                                       step="0.000001"
                                       value="${this.exportData.location.longitude || ''}"
                                       placeholder="9.1900"
                                       required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="state-province">State/Province *</label>
                                <input type="text" 
                                       id="state-province" 
                                       class="form-control"
                                       value="${this.exportData.location.stateProvince || ''}"
                                       placeholder="IT-25"
                                       required>
                                <small>Codice regione (es: IT-25 per Lombardia, US-CA per California)</small>
                            </div>

                            <div class="form-group">
                                <label for="country-code">Country Code *</label>
                                <input type="text" 
                                       id="country-code" 
                                       class="form-control"
                                       value="${this.exportData.location.countryCode || ''}"
                                       placeholder="IT"
                                       maxlength="2"
                                       required>
                                <small>Codice ISO a 2 lettere (es: IT, US, FR)</small>
                            </div>
                        </div>

                        <div class="location-helper">
                            <a href="https://www.google.com/maps" target="_blank" class="btn-link">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                                Trova coordinate su Google Maps
                            </a>
                            <a href="https://ebird.org/region/world" target="_blank" class="btn-link">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="2" y1="12" x2="22" y2="12"></line>
                                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                                </svg>
                                Trova codici eBird
                            </a>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4>Dettagli Osservazione</h4>

                        <div class="form-group">
                            <label for="protocol-type">Protocollo *</label>
                            <select id="protocol-type" class="form-control">
                                <option value="Stationary">Stationary - Punto fisso</option>
								<option value="NFC">Nocturnal Flight Call (NFC)</option>
                                <option value="Incidental">Incidental - Casuale</option>
                            </select>
                            <small>Per BirdNET-Pi fisso, usa "Stationary"</small>
                        </div>

                        <div class="form-group">
                            <label for="observers-count">Numero Osservatori</label>
                            <input type="number" 
                                   id="observers-count" 
                                   class="form-control"
                                   min="1"
                                   max="100"
                                   value="1">
                        </div>

                        <div class="form-group">
                            <label for="comments">Commenti Aggiuntivi</label>
                            <textarea id="comments" 
                                      class="form-control" 
                                      rows="3"
                                      placeholder="Detection automatiche tramite BirdNET-Pi..."></textarea>
                            <small>Informazioni aggiuntive sulla sessione di osservazione</small>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4>Opzioni Export</h4>

                        <label class="checkbox-label">
                            <input type="checkbox" id="include-audio-links" checked>
                            Includi riferimenti ai file audio nei commenti
                        </label>

                        <label class="checkbox-label">
                            <input type="checkbox" id="mark-uncertain">
                            Marca come "incerte" le specie con confidenza < 85%
                        </label>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * STEP 4: Riepilogo e export
     */
    renderSummaryStep() {
        const content = document.getElementById('wizard-content');
        
        // Prepara i dati finali
        this.prepareExportData();

        const speciesCount = this.exportData.species.length;
        const avgConfidence = this.calculateAverageConfidence();
        
        // Calcola numero di checklist orarie
        const hours = new Set(this.exportData.species.map(s => s.time.substring(0, 2)));
        const checklistCount = hours.size;

        content.innerHTML = `
            <div class="step-container">
                <h3>Riepilogo e Export</h3>
                <p class="step-description">
                    Controlla i dati prima di generare il file CSV per eBird.
                    Verranno create <strong>${checklistCount} checklist orarie</strong> separate.
                </p>

                <div class="summary-cards">
                    <div class="summary-card">
                        <div class="summary-card-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <div class="summary-card-content">
                            <div class="summary-card-label">Data</div>
                            <div class="summary-card-value">${this.formatDate(this.selectedDate)}</div>
                        </div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-card-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <div class="summary-card-content">
                            <div class="summary-card-label">Specie Totali</div>
                            <div class="summary-card-value">${speciesCount}</div>
                        </div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-card-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <div class="summary-card-content">
                            <div class="summary-card-label">Checklist Orarie</div>
                            <div class="summary-card-value">${checklistCount}</div>
                        </div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-card-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                            </svg>
                        </div>
                        <div class="summary-card-content">
                            <div class="summary-card-label">Confidenza Media</div>
                            <div class="summary-card-value">${avgConfidence.toFixed(0)}%</div>
                        </div>
                    </div>
                </div>

                <div class="location-summary">
                    <h4>📍 Dettagli Località</h4>
                    <div class="location-details-grid">
                        <div class="location-detail">
                            <span class="detail-label">Nome:</span>
                            <span class="detail-value">${this.exportData.location.locality || 'Non specificato'}</span>
                        </div>
                        <div class="location-detail">
                            <span class="detail-label">Coordinate:</span>
                            <span class="detail-value">${this.exportData.location.latitude || '?'}, ${this.exportData.location.longitude || '?'}</span>
                        </div>
                        <div class="location-detail">
                            <span class="detail-label">State/Province:</span>
                            <span class="detail-value">${this.exportData.location.stateProvince || 'Non specificato'}</span>
                        </div>
                        <div class="location-detail">
                            <span class="detail-label">Country Code:</span>
                            <span class="detail-value">${this.exportData.location.countryCode || 'Non specificato'}</span>
                        </div>
                        <div class="location-detail">
                            <span class="detail-label">Protocollo:</span>
                            <span class="detail-value">${this.exportData.protocol || 'Stationary'}</span>
                        </div>
                        <div class="location-detail">
                            <span class="detail-label">Osservatori:</span>
                            <span class="detail-value">${this.exportData.observers || 1}</span>
                        </div>
                    </div>
                </div>

                <div class="hourly-breakdown">
                    <h4>Distribuzione per Ora</h4>
                    <div class="hourly-breakdown-grid">
                        ${this.renderHourlyBreakdown()}
                    </div>
                </div>

                <div class="species-preview">
                    <h4>Specie da Esportare</h4>
                    <div class="species-preview-list">
                        ${this.exportData.species.map(sp => `
                            <div class="species-preview-item">
                                <span class="species-preview-name">
                                    ${sp.common_name}
                                    <small>${sp.scientific_name}</small>
                                </span>
                                <span class="species-preview-time">${sp.time}</span>
                                <span class="species-preview-confidence">${(sp.confidence * 100).toFixed(0)}%</span>
                            </div>
                        `).join('')}
                    </div>
                </div>

                <div class="export-info-box">
                    <h4>ℹ️ Formato Export</h4>
                    <ul>
                        <li><strong>Checklist orarie separate:</strong> ogni ora di osservazione (es: 08:00-08:59) genera una checklist distinta</li>
                        <li><strong>Durata fissa:</strong> 60 minuti per ogni checklist</li>
                        <li><strong>Ora esatta:</strong> conservata nel campo "Species Comments" (es: "Detected at 08:32:18, confidence: 82.0%")</li>
                        <li><strong>Start Time:</strong> inizio della fascia oraria (es: "08:00" per detection tra 08:00-08:59)</li>
                    </ul>
                </div>

                <div class="export-actions">
                    <button id="btn-export-csv" class="btn-export">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        Genera CSV per eBird
                    </button>

                    <p class="export-note">
                        Il file CSV conterrà ${checklistCount} checklist orarie separate.
                    </p>
                </div>
            </div>
        `;

        document.getElementById('btn-export-csv')?.addEventListener('click', () => {
            this.generateAndDownloadCSV();
        });
    }

    /**
     * Renderizza il breakdown orario
     */
    renderHourlyBreakdown() {
        const speciesByHour = {};
        
        this.exportData.species.forEach(sp => {
            const hour = sp.time.substring(0, 2);
            if (!speciesByHour[hour]) {
                speciesByHour[hour] = [];
            }
            speciesByHour[hour].push(sp);
        });

        return Object.keys(speciesByHour).sort().map(hour => {
            const count = speciesByHour[hour].length;
            return `
                <div class="hourly-breakdown-item">
                    <div class="breakdown-hour">${hour}:00-${hour}:59</div>
                    <div class="breakdown-count">${count} ${count === 1 ? 'specie' : 'specie'}</div>
                </div>
            `;
        }).join('');
    }

    /**
     * Utility functions
     */
    async fetchDetectionsByDate(date) {
        try {
            const response = await fetch(`/ebird-api.php?action=get_detections_by_date&date=${date}`);
            if (response.ok) {
                const data = await response.json();
                return data.detectionsByHour || {};
            }
        } catch (error) {
            console.error('Errore caricamento detection:', error);
        }

        // Dati di esempio per testing
        return this.generateMockDetections();
    }

    generateMockDetections() {
        const mockData = {
            '06': [
                { common_name: 'European Robin', scientific_name: 'Erithacus rubecula', confidence: 0.92, time: '06:15:32', filename: 'rec_001.wav', included: true, count: 'X' },
                { common_name: 'Common Blackbird', scientific_name: 'Turdus merula', confidence: 0.88, time: '06:45:12', filename: 'rec_002.wav', included: true, count: 'X' }
            ],
            '08': [
                { common_name: 'Great Tit', scientific_name: 'Parus major', confidence: 0.95, time: '08:10:05', filename: 'rec_003.wav', included: true, count: 'X' },
                { common_name: 'Blue Tit', scientific_name: 'Cyanistes caeruleus', confidence: 0.82, time: '08:32:18', filename: 'rec_004.wav', included: true, count: 'X' },
                { common_name: 'House Sparrow', scientific_name: 'Passer domesticus', confidence: 0.73, time: '08:55:42', filename: 'rec_005.wav', included: true, count: 'X' }
            ],
            '10': [
                { common_name: 'European Greenfinch', scientific_name: 'Chloris chloris', confidence: 0.89, time: '10:22:15', filename: 'rec_006.wav', included: true, count: 'X' }
            ]
        };
        return mockData;
    }

    toggleSpecies(hour, index, included) {
        if (this.detectionsByHour[hour] && this.detectionsByHour[hour][index]) {
            this.detectionsByHour[hour][index].included = included;
            
            const card = document.querySelector(`[data-hour="${hour}"][data-index="${index}"]`);
            if (card) {
                if (included) {
                    card.classList.remove('excluded');
                } else {
                    card.classList.add('excluded');
                }
            }
            
            this.updateReviewSummary();
        }
    }

    updateSpeciesCount(hour, index, value) {
        if (this.detectionsByHour[hour] && this.detectionsByHour[hour][index]) {
            // Valida l'input: accetta X o numeri
            const trimmed = value.trim().toUpperCase();
            
            if (trimmed === '' || trimmed === 'X') {
                this.detectionsByHour[hour][index].count = 'X';
            } else if (/^\d+$/.test(trimmed)) {
                // Solo numeri
                this.detectionsByHour[hour][index].count = trimmed;
            } else {
                // Valore non valido, ripristina X
                this.detectionsByHour[hour][index].count = 'X';
                const input = document.getElementById(`count-${hour}-${index}`);
                if (input) input.value = 'X';
            }
        }
    }

    autoRemoveLowConfidence(remove) {
        Object.keys(this.detectionsByHour).forEach(hour => {
            this.detectionsByHour[hour].forEach((detection, index) => {
                if (detection.confidence < 0.8) {
                    detection.included = !remove;
                    const checkbox = document.querySelector(`[data-hour="${hour}"][data-index="${index}"]`);
                    if (checkbox) {
                        checkbox.checked = !remove;
                    }
                    const card = checkbox?.closest('.species-card');
                    if (card) {
                        if (remove) {
                            card.classList.add('excluded');
                        } else {
                            card.classList.remove('excluded');
                        }
                    }
                }
            });
        });
        this.updateReviewSummary();
    }

    updateReviewSummary() {
        const uniqueSpecies = new Set();
        let totalDetections = 0;
        let removedCount = 0;

        Object.values(this.detectionsByHour).forEach(detections => {
            detections.forEach(d => {
                if (d.included !== false) {
                    uniqueSpecies.add(d.scientific_name);
                    totalDetections++;
                } else {
                    removedCount++;
                }
            });
        });

        document.getElementById('total-species-count').textContent = uniqueSpecies.size;
        document.getElementById('total-detections-count').textContent = totalDetections;
        document.getElementById('removed-count').textContent = removedCount;
    }

    prepareExportData() {
        this.exportData.species = [];
        
        Object.values(this.detectionsByHour).forEach(detections => {
            detections.forEach(d => {
                if (d.included !== false) {
                    this.exportData.species.push(d);
                }
            });
        });

        // I dati di configurazione sono già stati salvati in saveConfigurationData()
        // quando si è passati dallo Step 3 allo Step 4
    }

    calculateAverageConfidence() {
        if (this.exportData.species.length === 0) return 0;
        const sum = this.exportData.species.reduce((acc, sp) => acc + sp.confidence, 0);
        return (sum / this.exportData.species.length) * 100;
    }

    generateAndDownloadCSV() {
        const csv = this.createEbirdCSV();
        const filename = `ebird-export-${this.selectedDate}.csv`;
        
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        
        if (navigator.msSaveBlob) {
            navigator.msSaveBlob(blob, filename);
        } else {
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
        }

        // Mostra messaggio di successo
        this.showSuccessMessage(filename);
    }

    createEbirdCSV() {
        //let csv = 'Common Name,Genus,Species,Number,Species Comments,Location Name,Latitude,Longitude,Date,Start Time,State/Province,Country Code,Protocol,Number of Observers,Duration,All observations reported?,Distance Traveled,Area covered,Submission Comments\n';
		let csv = '';
        // Raggruppa le specie per ora
        const speciesByHour = {};
        
        this.exportData.species.forEach(species => {
            const hour = species.time.substring(0, 2); // Estrae l'ora (HH)
            
            if (!speciesByHour[hour]) {
                speciesByHour[hour] = {};
            }
            
            // Usa il nome scientifico come chiave per evitare duplicati
            const key = species.scientific_name;
            
            // Mantieni solo la detection con confidenza più alta per ogni specie in ogni ora
            if (!speciesByHour[hour][key] || species.confidence > speciesByHour[hour][key].confidence) {
                speciesByHour[hour][key] = {
                    ...species,
                    // Se la nuova detection ha count, usalo, altrimenti prova a preservare quello vecchio
                    count: species.count || (speciesByHour[hour][key] ? speciesByHour[hour][key].count : 'X'),
                    // Preserva anche il filename per il riferimento audio
                    filename: species.filename || (speciesByHour[hour][key] ? speciesByHour[hour][key].filename : '')																								 
                };
            }
        });

        // Crea le righe CSV, una checklist per ogni ora
        Object.keys(speciesByHour).sort().forEach(hour => {
            const hourSpeciesMap = speciesByHour[hour];
            const hourSpecies = Object.values(hourSpeciesMap);
            const startTime = `${hour}:00`; // Inizio ora: HH:00
            const duration = '60'; // Durata: 60 minuti
            
            // Commento generale per questa checklist oraria
            const hourlyComment = `${this.exportData.comments || 'Auto-generated from BirdNET-Pi recordings'} - Hourly checklist ${hour}:00-${hour}:59`;
            
            hourSpecies.forEach(species => {
                // Il nome scientifico completo va nel campo Species
                // Il campo Genus rimane vuoto
                const genus = '';
                const scientificName = species.scientific_name;
                const myDay = this.selectedDate.substring(8, 10); // Estrae l'ora (HH)
				const myMonth = this.selectedDate.substring(5, 7); // Estrae l'ora (HH)
				const myYear = this.selectedDate.substring(0, 4); // Estrae l'ora (HH)
				
				const myDate = `${myMonth}/${myDay}/${myYear}`;
                // Commento specie: solo la confidenza, senza ora
                let speciesComment = `Confidence: ${(species.confidence * 100).toFixed(1)}%`;
                if (this.exportData.includeAudioLinks && species.filename) {
                    speciesComment += ` | Audio: ${species.filename}`;
                }
                
                // Usa il count dalla detection, default 'X' se non presente
                const count = species.count || 'X';
				
  				const myStateProvince = '';
				if(this.exportData.location.countryCode.toUpperCase() == 'US'){
					myStateProvince = this.escapeCSV(this.exportData.location.stateProvince || '');
				}
				
                const row = [
                    this.escapeCSV(species.common_name),
                    this.escapeCSV(genus),
                    this.escapeCSV(scientificName),
                    count,
                    this.escapeCSV(speciesComment),
                    this.escapeCSV(this.exportData.location.locality),
                    this.exportData.location.latitude,
                    this.exportData.location.longitude,
                    myDate,
                    startTime,
                    myStateProvince,
                    this.escapeCSV(this.exportData.location.countryCode || ''),
                    this.exportData.protocol,
                    this.exportData.observers,
                    duration,
                    'N',
                    '',
                    '',
                    this.escapeCSV(hourlyComment)
                ];
                
                csv += row.join(',') + '\n';
            });
        });

        return csv;
    }

    escapeCSV(value) {
        if (typeof value !== 'string') return value;
        if (value.includes(',') || value.includes('"') || value.includes('\n')) {
            return `"${value.replace(/"/g, '""')}"`;
        }
        return value;
    }

    showSuccessMessage(filename) {
        const message = document.createElement('div');
        message.className = 'success-toast';
        message.innerHTML = `
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
            <div>
                <strong>Export completato!</strong>
                <p>File scaricato: ${filename}</p>
            </div>
        `;
        
        document.body.appendChild(message);
        
        setTimeout(() => {
            message.classList.add('show');
        }, 100);
        
        setTimeout(() => {
            message.classList.remove('show');
            setTimeout(() => message.remove(), 300);
        }, 5000);
    }

    handleDuplicateRemoval() {
            const speciesSeen = new Map();
            
            Object.keys(this.detectionsByHour).forEach(hour => {
                this.detectionsByHour[hour].forEach((detection, index) => {
                    const key = detection.scientific_name;
                    
                    if (!speciesSeen.has(key)) {
                        speciesSeen.set(key, { hour, index});
                    } 
                });
            });
        this.updateReviewSummary();
    }
	
    playAudio(filename) {
        // Implementazione riproduzione audio
        console.log('Riproduzione:', filename);
		var audio = new Audio(filename);
		audio.play();
        // Qui andrebbe implementata la logica per riprodurre il file audio
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('it-IT', { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
    }

    formatHour(hour) {
        return `${hour}:00 - ${hour}:59`;
    }

    updateProgressBar() {
        document.querySelectorAll('.progress-step').forEach((step, index) => {
            if (index < this.currentStep) {
                step.classList.add('completed');
                step.classList.remove('active');
            } else if (index === this.currentStep) {
                step.classList.add('active');
                step.classList.remove('completed');
            } else {
                step.classList.remove('active', 'completed');
            }
        });
    }

    updateNavigationButtons() {
        const prevBtn = document.getElementById('wizard-prev');
        const nextBtn = document.getElementById('wizard-next');
        
        prevBtn.style.display = this.currentStep > 0 ? 'block' : 'none';
        
        if (this.currentStep === 2) {
            nextBtn.style.display = 'none';
        } else {
            nextBtn.style.display = 'block';
            nextBtn.textContent = this.currentStep === 1 ? 'Vai al Riepilogo →' : 'Avanti →';
        }
    }

    async nextStep() {
        // Validazione prima di procedere
        if (!this.validateCurrentStep()) {
            return;
        }

        // Salva i dati dello Step 1 (Configurazione) prima di passare allo Step 2 (Riepilogo)
        if (this.currentStep === 1) {
            this.saveConfigurationData();
        }

        if (this.currentStep < 2) {
            await this.showStep(this.currentStep + 1);
        }
    }

    /**
     * Salva i dati di configurazione dallo Step 3
     */
    saveConfigurationData() {
        const locality = document.getElementById('location-name')?.value;
        const latitude = document.getElementById('latitude')?.value;
        const longitude = document.getElementById('longitude')?.value;
        const stateProvince = document.getElementById('state-province')?.value;
        const countryCode = document.getElementById('country-code')?.value;
        const protocol = document.getElementById('protocol-type')?.value;
        const observers = document.getElementById('observers-count')?.value;
        const comments = document.getElementById('comments')?.value;
        const includeAudioLinks = document.getElementById('include-audio-links')?.checked;

        if (locality) this.exportData.location.locality = locality;
        if (latitude) this.exportData.location.latitude = latitude;
        if (longitude) this.exportData.location.longitude = longitude;
        if (stateProvince) this.exportData.location.stateProvince = stateProvince;
        if (countryCode) this.exportData.location.countryCode = countryCode;
        if (protocol) this.exportData.protocol = protocol;
        if (observers) this.exportData.observers = parseInt(observers);
        if (comments !== undefined) this.exportData.comments = comments;
        this.exportData.includeAudioLinks = includeAudioLinks !== undefined ? includeAudioLinks : true;

        // Salva anche in localStorage per la prossima volta
        localStorage.setItem('birdnet_locality', locality || '');
        localStorage.setItem('birdnet_latitude', latitude || '');
        localStorage.setItem('birdnet_longitude', longitude || '');
        localStorage.setItem('birdnet_state_province', stateProvince || '');
        localStorage.setItem('birdnet_country_code', countryCode || '');
    }

    async previousStep() {
        if (this.currentStep > 0) {
            await this.showStep(this.currentStep - 1);
        }
    }

    validateCurrentStep() {
        switch(this.currentStep) {
            case 1:
                const locality = document.getElementById('location-name')?.value;
                const lat = document.getElementById('latitude')?.value;
                const lon = document.getElementById('longitude')?.value;
                const stateProvince = document.getElementById('state-province')?.value;
                const countryCode = document.getElementById('country-code')?.value;
                
                if (!locality || !lat || !lon || !stateProvince || !countryCode) {
                    alert('Completa tutti i campi obbligatori della localizzazione');
                    return false;
                }
                
                // Validazione formato Country Code (2 lettere)
                if (countryCode.length !== 2) {
                    alert('Il Country Code deve essere di 2 lettere (es: IT, US, FR)');
                    return false;
                }
                break;
        }
        return true;
    }
}

// Inizializza quando il DOM è pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.ebirdWizard = new eBirdExportWizard();
    });
} else {
    window.ebirdWizard = new eBirdExportWizard();
}

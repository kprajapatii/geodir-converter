/**
 * GeoDir Converter Module
 *
 * @package GeoDir_Converter
 */

(function ($, GeoDir_Converter) {
    'use strict';

    /**
     * Performs AJAX request.
     *
     * @param {string} action - Action to perform.
     * @param {Function} callback - Callback function.
     * @param {Object} data - Data to send (optional).
     * @param {Object} atts - Additional parameters for $.ajax (optional).
     * @return {jqXHR} jQuery XMLHttpRequest object.
     */
    GeoDir_Converter.ajax = function (action, callback, data, atts) {
        atts = typeof atts !== 'undefined' ? atts : {};
        data = typeof data !== 'undefined' ? data : {};

        const nonce = GeoDir_Converter.nonces.hasOwnProperty(action) ? GeoDir_Converter.nonces[action] : '';
        if (data instanceof FormData) {
            data.append('action', action);
            data.append('geodir_converter_nonce', nonce);

            atts.processData = false;
            atts.contentType = false;
        } else {
            data.action = action;
            data.geodir_converter_nonce = nonce;
        }

        atts = $.extend(atts, {
            url: GeoDir_Converter.ajaxUrl,
            dataType: 'json',
            data: data,
            success: function (response) {
                const success = true === response.success;
                const responseData = response.data || {};

                callback(success, responseData);
            },
        });

        return $.ajax(atts);
    }

    /**
     * Control Button base object.
     *
     * @type {Object}
     */
    GeoDir_Converter.ControlButton = {
        inSuspended: false,
        wasDisabled: false,
        defaultText: '',
        actionText: '',
        ajaxAction: '',
        converter: null,

        /**
         * Initializes the button.
         *
         * @param {jQuery} el - Button element.
         * @param {Object} args - Button arguments.
         * @return {Object} The button instance.
         */
        init: function (el, args) {
            this.element = el;
            this.defaultText = args.defaultText;
            this.actionText = args.actionText;
            this.ajaxAction = args.ajaxAction;
            this.converter = args.converter;

            this.element.on('click', this.click.bind(this));

            return this;
        },

        /**
         * Handles button click.
         *
         * @return {boolean} False if suspended, otherwise calls doAction.
         */
        click: function () {
            if (this.inSuspended) {
                return false;
            }
            this.doAction();
        },

        /**
         * Performs the button's action.
         */
        doAction: function () { },

        /**
         * Activates the button.
         */
        activate: function () {
            this.inSuspended = true;
            this.element.prop('disabled', true);
            this.element.text(this.actionText);
        },

        /**
         * Enables the button.
         */
        enable: function () {
            this.inSuspended = false;
            this.element.prop('disabled', false);
            this.element.text(this.defaultText);
        },

        /**
         * Disables the button.
         */
        disable: function () {
            this.inSuspended = false;
            this.element.prop('disabled', true);
            this.element.text(this.defaultText);
        },

        /**
         * Suspends the button.
         */
        suspend: function () {
            this.inSuspended = true;
            this.wasDisabled = !!this.element.prop('disabled');
            this.element.prop('disabled', true);
        },

        /**
         * Restores the button.
         */
        restore: function () {
            this.inSuspended = false;
            this.element.prop('disabled', this.wasDisabled);
        }
    };

    /**
     * Start Button Control.
     *
     * @type {Object}
     */
    GeoDir_Converter.ImportButton = $.extend({}, GeoDir_Converter.ControlButton, {
        doAction: function () {
            const self = this;
            const importerId = this.converter.importerId;
            const errorHandler = this.converter.errorHandler;
            const form = this.converter.settings.find('form');
            const files = this.converter.files;
            const settings = form.serializeObject();
            const test_mode = form.find('#test_mode').is(':checked') ? 'yes' : 'no';
            const formData = new FormData();

            if (!importerId) {
                return;
            }

            formData.append('test_mode', test_mode);
            formData.append('importerId', importerId);
            formData.append('settings', JSON.stringify(settings));

            if (files.length > 0) {
                for (let i = 0; i < files.length; i++) {
                    formData.append('files[]', files[i]);
                }
            }

            this.activate();
            errorHandler.hide();

            GeoDir_Converter.ajax(self.ajaxAction, function (success, data) {
                if (!success) {
                    self.enable();
                    self.converter.stop();
                    errorHandler.show(data.message);
                } else {
                    self.converter.start();
                }
            }, formData, {
                method: 'POST',
                contentType: false,
                processData: false,
            });
        }
    });

    /**
     * Configure Button Control.
     *
     * @type {Object}
     */
    GeoDir_Converter.ConfigureButton = $.extend({}, GeoDir_Converter.ControlButton, {
        activate: function () {
            this.element
                .addClass('btn-translucent-success')
                .removeClass('btn-outline-primary')
                .text(this.actionText);
        },
        enable: function () {
            this.element
                .addClass('btn-outline-primary')
                .removeClass('btn-translucent-success')
                .text(this.defaultText);
        },
        doAction: function () {
            const wrapper = $('.geodir-converter-wrapper');
            const converter = this.converter.element;
            const settings = this.converter.settings;

            wrapper.find('.card-header h6').text(GeoDir_Converter.i18n.importSource);

            wrapper.find('.geodir-converter-importer')
                .not(converter)
                .addClass('d-none');

            $('.geodir-converter-settings')
                .not(settings)
                .addClass('d-none');

            this.element.addClass('d-none');

            this.converter.backButton.element.removeClass('d-none');
            converter.addClass('border-bottom-0');
            settings.removeClass('d-none');
        }
    });

    /**
     * Cancel Button Control.
     *
     * @type {Object}
     */
    GeoDir_Converter.BackButton = $.extend({}, GeoDir_Converter.ControlButton, {
        doAction: function () {
            const wrapper = $('.geodir-converter-wrapper');
            const converter = this.converter.element;
            const settings = this.converter.settings;

            this.element.addClass('d-none');
            this.converter.configureButton.element.removeClass('d-none');
            converter.removeClass('border-bottom-0');

            settings.addClass("d-none");

            wrapper.find('.card-header h6').text(GeoDir_Converter.i18n.selectImport);
            wrapper.find('.geodir-converter-importer').removeClass('d-none');

            if (settings.find('form').length) {
                settings.find('form')[0].reset();
                this.converter.errorHandler.clear();
            }
        }
    });

    /**
    * Abort Button Control.
    *
    * @type {Object}
    */
    GeoDir_Converter.AbortButton = $.extend({}, GeoDir_Converter.ControlButton, {
        doAction: function () {
            this.activate();
            this.converter.stop();
            const importerId = this.converter.importerId;
            const self = this;

            GeoDir_Converter.ajax(self.ajaxAction, function (success, data) {
                self.converter.start();
                if (!success) {
                    self.enable();
                }
            }, { importerId }, { method: 'POST' });
        }
    });

    /**
     * Logs Handler.
     *
     * @type {Object}
     */
    GeoDir_Converter.LogsHandler = $.extend({}, {
        shown: 0,
        userInteracting: false,
        interactionTimeout: null,

        /**
         * Initializes the logs handler.
         *
         * @param {jQuery} el - Logs container element.
         * @return {Object} The logs handler instance.
         */
        init: function (el) {
            var self = this;
            this.element = el;

            if (!this.element.length || !this.element[0]) {
                return this;
            }

            this.element.scrollTop(this.element[0].scrollHeight);

            this.element.on('mouseenter', function () {
                self.userInteracting = true;
            });

            this.element.on('mouseleave', function () {
                clearTimeout(self.interactionTimeout);
                self.interactionTimeout = setTimeout(function () {
                    self.userInteracting = false;
                }, 1000);
            });

            this.element.on('wheel scroll touchstart', function () {
                if (!self.element.length || !self.element[0]) {
                    return;
                }

                self.userInteracting = true;
                clearTimeout(self.interactionTimeout);

                var element = self.element[0];
                var isAtBottom = Math.abs(element.scrollHeight - element.clientHeight - element.scrollTop) < 5;

                if (isAtBottom) {
                    self.interactionTimeout = setTimeout(function () {
                        self.userInteracting = false;
                    }, 500);
                } else {
                    self.interactionTimeout = setTimeout(function () {
                        self.userInteracting = false;
                    }, 3000);
                }
            });

            return this;
        },

        /**
         * Inserts logs into the container.
         *
         * @param {string} logs - Logs to insert.
         */
        insertLogs: function (logs) {
            if (!this.element.length || !this.element[0]) {
                return;
            }

            this.element.append(logs);

            if (!this.userInteracting) {
                this.element.scrollTop(this.element[0].scrollHeight);
            }
        },

        /**
         * Sets the number of logs shown.
         *
         * @param {number} count - Number of logs shown.
         */
        setShown: function (count) {
            this.shown = count;
        },

        /**
         * Clears the logs.
         */
        clear: function () {
            this.shown = 0;
            this.userInteracting = false;
            clearTimeout(this.interactionTimeout);
            if (this.element.length) {
                this.element.html('');
            }
        },

    });

    /**
    * Error Handler.
    *
    * @type {Object}
    */
    GeoDir_Converter.ErrorHandler = {
        /**
         * Initializes the error handler.
         * @param {jQuery} el - The element where errors will be displayed.
         * @returns {Object} - The error handler instance.
         */
        init: function (el) {
            this.element = el;
            return this;
        },

        /**
         * Displays an error message.
         * @param {string} message - The error message to display.
         */
        show: function (message) {
            this.element.html(message).removeClass('d-none');
        },

        /**
         * Hides the error message.
         */
        hide: function () {
            this.element.html('').addClass('d-none');
        },

        /**
         * Clears the error message and hides the error container.
         */
        clear: function () {
            this.hide();
        },

        /**
         * Checks if the error container is currently visible.
         * @returns {boolean} - True if the error container is visible, otherwise false.
         */
        isVisible: function () {
            return !this.element.hasClass('d-none');
        }
    };

    /**
     * Progress Bar.
     *
     * @type {Object}
     */
    GeoDir_Converter.ProgressBar = $.extend({}, {
        barEl: null,

        /**
         * Initializes the progress bar.
         *
         * @param {jQuery} el - Progress bar container element.
         * @return {Object} The progress bar instance.
         */
        init: function (el) {
            this.element = el;
            this.barEl = this.element.find('.progress-bar');
            return this;
        },

        /**
         * Updates the progress bar.
         *
         * @param {number} newProgress - New progress percentage.
         */
        updateProgress: function (newProgress) {
            this.element.removeClass('d-none');
            this.barEl.css('width', newProgress + '%').text(newProgress + '%');
        }
    });

    /**
     * CSV Upload Handler.
     *
     * Handles drag & drop, file selection, and AJAX upload with progress bar.
     */
    GeoDir_Converter.DropZone = $.extend({}, {
        dropzone: null,
        input: null,
        btn: null,
        uploads: null,

        /**
         * Initializes the drop zone.
         *
         */
        init: function (el, args) {
            this.element = el;
            this.converter = args.converter;
            this.dropzone = this.element.find('.geodir-converter-drop-zone');
            this.btn = this.element.find('.geodir-converter-files-btn');
            this.input = this.element.find('.geodir-converter-files-input');
            this.uploads = this.element.find('.geodir-converter-uploads');

            const self = this;

            this.disableStep2Inputs(true);

            this.btn.on('click', function () {
                self.input.trigger('click');
            });

            this.dropzone.on('dragover dragenter', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.dropzone.addClass('dragover');
            });

            this.dropzone.on('dragleave dragend drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.dropzone.removeClass('dragover');
            });

            this.dropzone.on('drop', function (e) {
                self.handleFiles(e.originalEvent.dataTransfer.files);
            });

            this.input.on('change', function (e) {
                e.preventDefault();
                self.handleFiles(this.files);
            });

            return this;
        },

        /**
         * Handles CSV files dropped or selected.
         *
         * @param {FileList} files
         */
        handleFiles: function (files) {
            const self = this;
            const importerId = this.converter ? this.converter.importerId : 'edirectory';
            const isCSV = importerId === 'csv';

            Array.from(files).forEach(function (file) {
                const isCSVFile = file.name.toLowerCase().endsWith('.csv') || file.name.toLowerCase().endsWith('.txt');
                if (isCSVFile || !isCSV) {
                    self.uploadFile(file);
                } else {
                    aui_toast("geodir_converter_error", "error", `${file.name} is not a CSV file.`);
                }
            });
        },

        /**
         * Uploads a single file.
         *
         * @param {File} file - The file to upload.
         * @returns {void}
         */
        uploadFile: function (file) {
            const importerId = this.converter ? this.converter.importerId : 'edirectory';
            const uploadContext = this._createUploadContext(file, importerId);

            if (importerId === 'csv') {
                this._uploadCSVFile(uploadContext);
            } else {
                this._uploadEDirectoryFile(uploadContext);
            }
        },

        /**
         * Creates upload context object with UI elements and progress tracking.
         *
         * @private
         * @param {File} file - The file being uploaded.
         * @param {string} importerId - The importer identifier.
         * @returns {Object} Upload context object.
         */
        _createUploadContext: function (file, importerId) {
            const fileId = 'upload-' + Date.now();
            const item = this.renderUploadItem(fileId, file.name);
            const progressBar = $.extend({}, GeoDir_Converter.ProgressBar);
            const progress = progressBar.init(item.find('.progress'));

            return {
                file: file,
                fileId: fileId,
                item: item,
                progress: progress,
                status: item.find('.geodir-converter-progress-status'),
                icon: item.find('.geodir-converter-progress-icon'),
                importerId: importerId
            };
        },

        /**
         * Handles CSV file upload.
         *
         * @private
         * @param {Object} context - Upload context object.
         * @returns {void}
         */
        _uploadCSVFile: function (context) {
            const self = this;
            const formData = this._buildCSVFormData(context.file);

            GeoDir_Converter.ajax(GeoDir_Converter.actions.csv_parse, function (success, data) {
                self._handleUploadResponse(success, data, context, function () {
                    if (data.file_id) {
                        const delimiter = self.element.find('#csv_delimiter').val() || ',';
                        self.converter.switchToCSVMappingStep({
                            file_id: data.file_id,
                            delimiter: delimiter,
                            headers: data.headers || []
                        });
                    }
                });
            }, formData, this._getUploadAjaxOptions(context));
        },

        /**
         * Handles eDirectory file upload.
         *
         * @private
         * @param {Object} context - Upload context object.
         * @returns {void}
         */
        _uploadEDirectoryFile: function (context) {
            const self = this;
            const formData = this._buildEDirectoryFormData(context.file, context.importerId);
            const $moduleTypes = this.element.find('[name="edirectory_modules[]"]');

            GeoDir_Converter.ajax(GeoDir_Converter.actions.upload, function (success, data) {
                self._handleUploadResponse(success, data, context, function () {
                    if (data.module_type) {
                        const selected = $moduleTypes.map(function () {
                            return $(this).val();
                        }).get();

                        if (!selected.includes(data.module_type)) {
                            selected.push(data.module_type);
                            $moduleTypes.remove();

                            const hiddenInputs = selected.map(function (val) {
                                return '<input type="hidden" name="edirectory_modules[]" value="' + val + '">';
                            }).join('');

                            self.element.append(hiddenInputs);
                        }
                    }
                });
            }, formData, this._getUploadAjaxOptions(context));
        },

        /**
         * Builds FormData for CSV upload.
         *
         * @private
         * @param {File} file - The file to upload.
         * @returns {FormData} FormData object for CSV upload.
         */
        _buildCSVFormData: function (file) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('importerId', 'csv');

            const delimiter = this.element.find('#csv_delimiter').val() || ',';
            formData.append('csv_delimiter', delimiter);

            const postType = this.element.find('select[name="gd_post_type"]').val();
            if (postType) {
                formData.append('gd_post_type', postType);
            }

            return formData;
        },

        /**
         * Builds FormData for eDirectory upload.
         *
         * @private
         * @param {File} file - The file to upload.
         * @param {string} importerId - The importer identifier.
         * @returns {FormData} FormData object for eDirectory upload.
         */
        _buildEDirectoryFormData: function (file, importerId) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('importerId', importerId);
            return formData;
        },

        /**
         * Gets AJAX options for file upload with progress tracking.
         *
         * @private
         * @param {Object} context - Upload context object.
         * @returns {Object} AJAX options object.
         */
        _getUploadAjaxOptions: function (context) {
            const self = this;
            const progress = context.progress;
            const icon = context.icon;
            const status = context.status;

            return {
                method: 'POST',
                xhr: function () {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function (evt) {
                        if (evt.lengthComputable) {
                            const percent = Math.round((evt.loaded / evt.total) * 100);
                            progress.updateProgress(percent);
                        }
                    }, false);
                    return xhr;
                },
                error: function () {
                    progress.barEl.removeClass('progress-bar-animated').addClass('bg-danger');
                    icon.removeClass('fa-sync').addClass('fa-triangle-exclamation text-danger');
                    status.text(GeoDir_Converter.i18n.serverErrorUpload);
                    self.disableStep2Inputs(true);
                }
            };
        },

        /**
         * Handles upload response and updates UI accordingly.
         *
         * @private
         * @param {boolean} success - Whether the upload was successful.
         * @param {Object} data - Response data from server.
         * @param {Object} context - Upload context object.
         * @param {Function} onSuccessCallback - Callback to execute on success.
         * @returns {void}
         */
        _handleUploadResponse: function (success, data, context, onSuccessCallback) {
            const progress = context.progress;
            const icon = context.icon;
            const status = context.status;
            const file = context.file;

            progress.barEl.removeClass('progress-bar-animated');
            icon.removeClass('fa-sync');

            if (success) {
                progress.barEl.addClass('bg-success');
                icon.addClass('fa-check text-success');
                status.text(data.message || GeoDir_Converter.i18n.fileUploadSuccess);

                if (!this.converter.files.some(function (f) {
                    return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
                })) {
                    this.converter.files.push(file);
                }

                if (onSuccessCallback) {
                    onSuccessCallback();
                }

                this.disableStep2Inputs(false);
            } else {
                progress.barEl.addClass('bg-danger');
                icon.addClass('fa-triangle-exclamation text-danger');
                status.text(GeoDir_Converter.i18n.uploadFailed + (data.message || GeoDir_Converter.i18n.unknownError));
                this.disableStep2Inputs(true);
            }
        },

        /**
         * Disables/enables step 2 inputs.
         *
         * @param {boolean} disabled
         */
        disableStep2Inputs: function (disabled) {
            const configureWrapper = this.element.find('.geodir-converter-configure-wrapper');
            if (configureWrapper.length) {
                configureWrapper.find('input, select, textarea, button').not('[name="edirectory_modules[]"]').prop('disabled', disabled);
            }
        },


        /**
         * Renders an upload item.
         * 
         * @param {string} id
         * @param {string} name
         */
        renderUploadItem: function (id, name) {
            const item = $(`
                <div class="upload-item my-2" data-id="${id}">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-truncate">${name}</span>
                        <i class="fas fa-solid fa-sync text-muted ms-2 geodir-converter-progress-icon" aria-hidden="true"></i>
                    </div>
                    <div class="progress my-1 d-none" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                    </div>
                    <div class="geodir-converter-progress-status small text-muted mt-1">${GeoDir_Converter.i18n.uploading}</div>
                </div>
            `);

            this.uploads.append(item);

            return this.uploads.find(`[data-id="${id}"]`);
        }
    });

    /**
     * Converter main object.
     *
     * @type {Object}
     */
    GeoDir_Converter.Converter = {
        /**
         * Interval for regular progress checks (in milliseconds).
         * @type {number}
         */
        tickInterval: 2000,

        /**
         * Interval for the initial progress check (in milliseconds).
         * provides quick feedback when the import starts.
         * @type {number}
         */
        shortTickInterval: 400,
        retriesCount: 1,
        retriesLeft: 0,
        inProgress: false,
        updateTimeout: null,
        preventUpdates: false,
        importerId: null,
        files: [],

        /**
         * Initializes the converter.
         *
         * @param {jQuery} el - Converter container element.
         * @param {Object} args - Converter arguments.
         * @return {Object} The converter instance.
         */
        init: function (el, args) {
            this.element = el;
            this.inProgress = args.inProgress;
            this.resetRetries();

            this.importerId = this.element.data('importer');
            this.settings = this.element.find('.geodir-converter-settings');

            let progressBar = $.extend({}, GeoDir_Converter.ProgressBar);
            this.progressBar = progressBar.init(this.element.find('.geodir-converter-progress'));

            let logsHandler = $.extend({}, GeoDir_Converter.LogsHandler);
            this.logsHandler = logsHandler.init(this.element.find('.geodir-converter-logs'));

            let configureButton = $.extend({}, GeoDir_Converter.ConfigureButton);
            this.configureButton = configureButton.init(this.element.find('.geodir-converter-configure'), {
                defaultText: GeoDir_Converter.i18n.runConverter,
                actionText: GeoDir_Converter.i18n.importing,
                converter: this
            });

            let backButton = $.extend({}, GeoDir_Converter.BackButton);
            this.backButton = backButton.init(this.element.find('.geodir-converter-back'), {
                converter: this
            });

            let importButton = $.extend({}, GeoDir_Converter.ImportButton);
            this.importButton = importButton.init(this.element.find('.geodir-converter-import'), {
                defaultText: GeoDir_Converter.i18n.import,
                actionText: GeoDir_Converter.i18n.importing,
                ajaxAction: GeoDir_Converter.actions.import,
                converter: this
            });

            let abortButton = $.extend({}, GeoDir_Converter.AbortButton);
            this.abortButton = abortButton.init(this.element.find('.geodir-converter-abort'), {
                defaultText: GeoDir_Converter.i18n.abort,
                actionText: GeoDir_Converter.i18n.aborting,
                ajaxAction: GeoDir_Converter.actions.abort,
                converter: this
            });

            let errorHandler = $.extend({}, GeoDir_Converter.ErrorHandler);
            this.errorHandler = errorHandler.init(this.element.find('.geodir-converter-error'), {
                converter: this
            });

            const connectWrapper = this.element.find('.geodir-converter-connect-wrapper');
            if (connectWrapper.length) {
                let dropZone = $.extend({}, GeoDir_Converter.DropZone);
                this.dropZone = dropZone.init(connectWrapper, {
                    converter: this
                });
            }

            if (this.inProgress) {
                this.start();
            }

            this.element.data('converter', this);

            return this;
        },

        /**
         * Switches to CSV mapping step dynamically.
         *
         * @param {Object} data - Response data containing file_id, delimiter, and headers.
         * @param {number} data.file_id - The uploaded CSV file attachment ID.
         * @param {string} [data.delimiter=','] - The CSV delimiter used.
         * @param {Array} [data.headers=[]] - Array of CSV column headers.
         * @returns {void}
         */
        switchToCSVMappingStep: function (data) {
            const form = this.element.find('.geodir-converter-csv-form');
            if (!form.length) {
                return;
            }

            GeoDir_Converter.ajax(GeoDir_Converter.actions.csv_get_mapping_step, function (success, responseData) {
                if (!success) {
                    alert(responseData.message || GeoDir_Converter.i18n.failedLoadMapping);
                    return;
                }

                const $responseForm = $(responseData.html);
                form.html($responseForm.html());
                this._initializeMappingStepButtons(form);
            }.bind(this), {
                file_id: data.file_id,
                delimiter: data.delimiter || ','
            }, {
                method: 'POST'
            });
        },

        /**
         * Initializes buttons and Select2 in the mapping step.
         *
         * @private
         * @param {jQuery} container - The container element (form or mapping step).
         * @returns {void}
         */
        _initializeMappingStepButtons: function (container) {
            const importBtn = container.find('.geodir-converter-import');
            const abortBtn = container.find('.geodir-converter-abort');
            
            if (typeof aui_init_select2 === 'function') {
                aui_init_select2();
            }

            if (importBtn.length) {
                const importButton = $.extend({}, GeoDir_Converter.ImportButton);
                this.importButton = importButton.init(importBtn, {
                    defaultText: GeoDir_Converter.i18n.import,
                    actionText: GeoDir_Converter.i18n.importing,
                    ajaxAction: GeoDir_Converter.actions.import,
                    converter: this
                });
            }

            if (abortBtn.length) {
                const abortButton = $.extend({}, GeoDir_Converter.AbortButton);
                this.abortButton = abortButton.init(abortBtn, {
                    defaultText: GeoDir_Converter.i18n.abort,
                    actionText: GeoDir_Converter.i18n.aborting,
                    ajaxAction: GeoDir_Converter.actions.abort,
                    converter: this
                });
            }
        },

        /**
         * Starts the converter.
         */
        start: function () {
            this.preventUpdates = false;
            this.logsHandler.clear();
            this.updateTimeout = setTimeout(this.tick.bind(this), this.shortTickInterval);
        },

        /**
         * Stops the converter.
         */
        stop: function () {
            clearTimeout(this.updateTimeout);
            this.preventUpdates = true;
        },

        /**
         * Resets the retry count.
         */
        resetRetries: function () {
            this.retriesLeft = this.retriesCount;
        },

        /**
         * Performs a tick of the converter.
         */
        tick: function () {
            const self = this;

            GeoDir_Converter.ajax(GeoDir_Converter.actions.progress, function (success, data) {
                if (self.preventUpdates) {
                    return;
                }

                if (!success) {
                    if (self.retriesLeft > 0) {
                        self.retriesLeft--;
                        self.updateTimeout = setTimeout(self.tick.bind(self), self.tickInterval);
                    } else {
                        self.abortButton.disable();
                    }
                    return;
                }

                self.resetRetries();

                data.inProgress ? self.markInProgress() : self.markStopped();
                data.inProgress ? self.configureButton.activate() : self.configureButton.enable();

                self.progressBar.updateProgress(data.progress);
                self.logsHandler.setShown(data.logsShown);
                self.logsHandler.insertLogs(data.logs);

                if (self.dropZone && self.dropZone.btn) {
                    self.dropZone.btn.prop('disabled', data.inProgress);
                }

                if (self.inProgress) {
                    self.updateTimeout = setTimeout(self.tick.bind(self), self.tickInterval);
                } else {
                    self.abortButton.disable();
                    self.importButton.enable();
                }

            }, { logsShown: self.logsHandler.shown, importerId: this.importerId });
        },

        /**
         * Marks the converter as in progress.
         */
        markInProgress: function () {
            this.inProgress = true;
            this.importButton.activate();
            this.abortButton.enable();
        },

        /**
         * Marks the converter as stopped.
         */
        markStopped: function () {
            this.inProgress = false;
            this.importButton.enable();
            this.abortButton.disable();
        }
    };

    /**
     * CSV Importer Handler.
     *
     * @type {Object}
     */
    GeoDir_Converter.CSVImporter = {
        init: function () {
            const self = this;
            const csvForm = $('.geodir-converter-csv-form');

            if (!csvForm.length) {
                return;
            }

            if (typeof aui_init_select2 === 'function') {
                aui_init_select2();
            }

            $(document).on('click', '.geodir-converter-csv-back', function (e) {
                e.preventDefault();
                self.goBack();
            });

            $(document).on('click', '.geodir-converter-refresh-fields', function (e) {
                e.preventDefault();
                self.refreshFields();
            });

            $(document).on('change', '.geodir-converter-csv-form select[name="gd_post_type"]', function () {
                self.refreshFields();
            });

            $(document).on('click', '.geodir-converter-save-template', function (e) {
                e.preventDefault();
                self.saveTemplate();
            });

            $(document).on('click', '.geodir-converter-load-template', function (e) {
                e.preventDefault();
                self.loadTemplate();
            });

            $(document).on('click', '.geodir-converter-delete-template', function (e) {
                e.preventDefault();
                self.deleteTemplate();
            });
        },

        /**
         * Refreshes the mapping fields table.
         *
         * @returns {void}
         */
        refreshFields: function () {
            const form = $('.geodir-converter-csv-form');
            const postType = form.find('select[name="gd_post_type"]').val();
            const wrapper = $('#geodir-converter-csv-mapping-wrapper');
            const btn = $('.geodir-converter-refresh-fields');

            if (!postType) {
                return;
            }

            btn.prop('disabled', true).find('i').addClass('fa-spin');

            GeoDir_Converter.ajax(GeoDir_Converter.actions.csv_refresh_fields, function (success, data) {
                btn.prop('disabled', false).find('i').removeClass('fa-spin');

                if (success) {
                    wrapper.html(data.html);
                    if (typeof aui_init_select2 === 'function') {
                        aui_init_select2();
                    }
                } else {
                    wrapper.html('<div class="alert alert-danger">' + (data.message || GeoDir_Converter.i18n.failedRefreshFields) + '</div>');
                }
            }, {
                gd_post_type: postType
            }, {
                method: 'POST'
            });
        },

        /**
         * Returns to the upload step from the mapping step.
         *
         * @returns {void}
         */
        goBack: function () {
            const form = $('.geodir-converter-csv-form');
            const converterElement = form.closest('.geodir-converter-importer');
            const converter = converterElement.length ? converterElement.data('converter') : null;
            const $backButton = $('.geodir-converter-csv-back');
            const originalText = $backButton.html();
            const originalDisabled = $backButton.prop('disabled');

            $backButton.prop('disabled', true);
            $backButton.html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + GeoDir_Converter.i18n.loading);

            GeoDir_Converter.ajax(GeoDir_Converter.actions.csv_clear_file, function (success, data) {
                $backButton.prop('disabled', originalDisabled);
                $backButton.html(originalText);

                if (!success) {
                    alert(data.message || GeoDir_Converter.i18n.failedClearFile);
                    return;
                }

                const $responseForm = $(data.html);
                form.html($responseForm.html());

                if (converter) {
                    const connectWrapper = form.find('.geodir-converter-connect-wrapper');
                    if (connectWrapper.length) {
                        const dropZone = $.extend({}, GeoDir_Converter.DropZone);
                        converter.dropZone = dropZone.init(connectWrapper, {
                            converter: converter
                        });
                    }
                }

                if (typeof aui_init_select2 === 'function') {
                    aui_init_select2();
                }
            }, {}, {
                method: 'POST'
            });
        },

        /**
         * Saves the current mapping as a template.
         *
         * @returns {void}
         */
        saveTemplate: function () {
            const form = $('.geodir-converter-csv-form');
            const templateNameInput = $('#csv_template_name');
            const templateName = templateNameInput.val().trim();
            const saveBtn = $('.geodir-converter-save-template');

            if (!templateName) {
                aui_toast('geodir_converter_error', 'error', GeoDir_Converter.i18n.templateNameRequired);
                templateNameInput.focus();
                return;
            }

            // Get all mapping values from the form.
            const mapping = {};
            form.find('select.geodir-converter-field-mapping').each(function () {
                const $select = $(this);
                const columnName = $select.attr('name').replace('csv_mapping[', '').replace(']', '');
                const fieldValue = $select.val();
                if (fieldValue) {
                    mapping[columnName] = fieldValue;
                }
            });

            if (Object.keys(mapping).length === 0) {
                aui_toast('geodir_converter_error', 'error', GeoDir_Converter.i18n.templateMappingRequired);
                return;
            }

            saveBtn.prop('disabled', true);

            GeoDir_Converter.ajax(GeoDir_Converter.actions.csv_save_template, function (success, data) {
                saveBtn.prop('disabled', false);

                if (success) {
                    templateNameInput.val('');
                    aui_toast('geodir_converter_success', 'success', data.message || GeoDir_Converter.i18n.templateSaved);
                    // Refresh template list dynamically.
                    self.refreshTemplateList(data.template_id, data.template_name);
                } else {
                    aui_toast('geodir_converter_error', 'error', data.message || GeoDir_Converter.i18n.templateSaveFailed);
                }
            }, {
                template_name: templateName,
                csv_mapping: mapping
            }, {
                method: 'POST'
            });
        },

        /**
         * Loads a saved mapping template.
         *
         * @returns {void}
         */
        loadTemplate: function () {
            const templateSelect = $('#csv_template_select');
            const templateId = templateSelect.val();
            const loadBtn = $('.geodir-converter-load-template');

            if (!templateId) {
                aui_toast('geodir_converter_error', 'error', GeoDir_Converter.i18n.templateSelectRequired);
                return;
            }

            loadBtn.prop('disabled', true);

            GeoDir_Converter.ajax(GeoDir_Converter.actions.csv_load_template, function (success, data) {
                loadBtn.prop('disabled', false);

                if (success) {
                    const form = $('.geodir-converter-csv-form');
                    const mapping = data.mapping || {};

                    // Apply mapping to form selects.
                    Object.keys(mapping).forEach(function (columnName) {
                        const fieldValue = mapping[columnName];
                        const $select = form.find('select[name="csv_mapping[' + columnName + ']"]');
                        if ($select.length) {
                            // Set the value
                            $select.val(fieldValue);
                            
                            // Update Select2 if it's initialized
                            if ($select.hasClass('select2-hidden-accessible')) {
                                // Use Select2 API to update
                                if ($select.data('select2')) {
                                    $select.trigger('change.select2');
                                } else {
                                    $select.trigger('change');
                                }
                            } else {
                                $select.trigger('change');
                            }
                        }
                    });

                    // Reinitialize Select2 to ensure all changes are reflected
                    setTimeout(function() {
                        if (typeof aui_init_select2 === 'function') {
                            aui_init_select2();
                        }
                        // Also manually trigger change on all selects to update Select2
                        form.find('.geodir-converter-field-mapping').each(function() {
                            const $sel = $(this);
                            if ($sel.hasClass('select2-hidden-accessible')) {
                                $sel.trigger('change.select2');
                            }
                        });
                    }, 150);

                    aui_toast('geodir_converter_success', 'success', data.message || GeoDir_Converter.i18n.templateLoaded);
                } else {
                    aui_toast('geodir_converter_error', 'error', data.message || GeoDir_Converter.i18n.templateLoadFailed);
                }
            }, {
                template_id: templateId
            }, {
                method: 'POST'
            });
        },

        /**
         * Deletes a saved mapping template.
         *
         * @returns {void}
         */
        deleteTemplate: function () {
            const templateSelect = $('#csv_template_select');
            const templateId = templateSelect.val();
            const deleteBtn = $('.geodir-converter-delete-template');

            if (!templateId) {
                aui_toast('geodir_converter_error', 'error', GeoDir_Converter.i18n.templateSelectRequired);
                return;
            }

            if (!confirm(GeoDir_Converter.i18n.templateDeleteConfirm)) {
                return;
            }

            deleteBtn.prop('disabled', true);

            GeoDir_Converter.ajax(GeoDir_Converter.actions.csv_delete_template, function (success, data) {
                deleteBtn.prop('disabled', false);

                if (success) {
                    const templateName = templateSelect.find('option[value="' + templateId + '"]').data('name') || '';
                    templateSelect.val('').find('option[value="' + templateId + '"]').remove();
                    
                    // If no templates left, hide the load section.
                    if (templateSelect.find('option').length <= 1) {
                        self.hideTemplateLoadSection();
                    }
                    
                    aui_toast('geodir_converter_success', 'success', data.message || GeoDir_Converter.i18n.templateDeleted);
                } else {
                    aui_toast('geodir_converter_error', 'error', data.message || GeoDir_Converter.i18n.templateDeleteFailed);
                }
            }, {
                template_id: templateId
            }, {
                method: 'POST'
            });
        },

        /**
         * Refreshes the template dropdown list.
         *
         * @param {string} newTemplateId - The ID of the newly added template.
         * @param {string} newTemplateName - The name of the newly added template.
         * @returns {void}
         */
        refreshTemplateList: function (newTemplateId, newTemplateName) {
            let templateSelect = $('#csv_template_select');
            const templatesSection = $('.geodir-converter-templates-section');
            const row = templatesSection.find('.row');
            let loadSection = $('.geodir-converter-template-load-section');
            
            // If load section doesn't exist, create it.
            if (!loadSection.length) {
                const saveSection = $('.geodir-converter-template-save-section');
                
                // Create the load section HTML
                const loadSectionHtml = $('<div>', {
                    class: 'col-md-6 geodir-converter-template-load-section',
                    html: '<label class="form-label mb-2">' + GeoDir_Converter.i18n.loadTemplate + '</label>' +
                          '<div class="input-group">' +
                          '<select class="form-select form-select-sm" id="csv_template_select">' +
                          '<option value="">' + GeoDir_Converter.i18n.chooseTemplate + '</option>' +
                          '</select>' +
                          '<button type="button" class="btn btn-sm btn-primary geodir-converter-load-template" title="' + GeoDir_Converter.i18n.loadSelectedTemplate + '">' +
                          '<i class="fas fa-arrow-down"></i>' +
                          '</button>' +
                          '<button type="button" class="btn btn-sm btn-outline-danger geodir-converter-delete-template" title="' + GeoDir_Converter.i18n.deleteSelectedTemplate + '">' +
                          '<i class="fas fa-trash-alt"></i>' +
                          '</button>' +
                          '</div>'
                });
                
                // Insert before save section
                if (saveSection.length) {
                    saveSection.before(loadSectionHtml);
                    saveSection.removeClass('col-12').addClass('col-md-6');
                } else {
                    row.prepend(loadSectionHtml);
                }
                
                // Re-select the element
                templateSelect = $('#csv_template_select');
                loadSection = $('.geodir-converter-template-load-section');
            } else if (loadSection.hasClass('d-none')) {
                // If load section is hidden, show it.
                loadSection.removeClass('d-none');
                // Adjust save section to col-md-6 if it was col-12.
                const saveSection = $('.geodir-converter-template-save-section');
                if (saveSection.length) {
                    saveSection.removeClass('col-12').addClass('col-md-6');
                }
            }
            
            // Add new template option if provided.
            if (newTemplateId && newTemplateName) {
                const newOption = $('<option>', {
                    value: newTemplateId,
                    text: newTemplateName,
                    'data-name': newTemplateName
                });
                templateSelect.append(newOption);
                templateSelect.val(newTemplateId);
                
                // Update Select2 if it's initialized
                if (templateSelect.hasClass('select2-hidden-accessible')) {
                    templateSelect.trigger('change.select2');
                }
            }
            
            // Reinitialize Select2 if available.
            if (typeof aui_init_select2 === 'function') {
                aui_init_select2();
            }
        },

        /**
         * Hides the template load section when no templates exist.
         *
         * @returns {void}
         */
        hideTemplateLoadSection: function () {
            const loadSection = $('.geodir-converter-template-load-section');
            
            if (loadSection.length) {
                loadSection.addClass('d-none');
                // Expand save section to full width.
                const saveSection = $('.geodir-converter-template-save-section');
                if (saveSection.length) {
                    saveSection.removeClass('col-md-6').addClass('col-12');
                }
            }
        }
    };

    $(function () {
        const importers = $('.geodir-converter-importer');
        importers.each(function () {
            let converter = $.extend({}, GeoDir_Converter.Converter);
            converter.init($(this), {
                inProgress: Boolean($(this).data('progress'))
            });
        });

        GeoDir_Converter.CSVImporter.init();
    });

    /**
     * Serializes a form to an object.
     *
     * @returns {Object} The serialized object.
     */
    $.fn.serializeObject = function () {
        let o = {};
        let a = this.serializeArray();
        $.each(a, function () {
            let name = this.name.replace(/\[\]$/, '');
            let value = this.value || '';

            if (name.indexOf('[') > -1) {
                let parts = name.split('[');
                let root = parts[0];
                let key = parts[1].replace(/\]$/, '');

                o[root] = o[root] || {};
                o[root][key] = value;
            } else {
                o[name] = value;
            }
        });
        return o;
    };
}(jQuery, GeoDir_Converter));
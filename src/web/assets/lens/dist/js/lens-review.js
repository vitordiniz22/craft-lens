/**
 * Lens Plugin - Review (Simplified)
 * Handles interactions for Twig-rendered review pages
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    // ==========================================================================
    // Review View - Single Analysis Review
    // ==========================================================================

    var LensReviewView = {
        zoomLevel: 0,
        ZOOM_STEPS: [1, 1.25, 1.5, 2, 2.5, 3, 4],

        init: function() {
            if (!document.getElementById('lens-review-view')) {
                return; // Not on review view page
            }

            this.bindKeyboardShortcuts();
            this.bindFocalPointClick();
            this.bindZoomControls();
            this.bindFormSubmit();
            this.initPeopleDetectionEditor();
        },

        // Keyboard shortcuts: A (approve), S (skip), R (reject)
        bindKeyboardShortcuts: function() {
            var self = this;
            document.addEventListener('keydown', function(e) {
                // Don't trigger if user is typing in an input/textarea
                if (e.target.matches('input, textarea')) {
                    return;
                }

                var key = e.key.toLowerCase();

                if (key === 'a') {
                    e.preventDefault();
                    var approveBtn = document.querySelector('button[type="submit"].submit');
                    if (approveBtn && !approveBtn.disabled) {
                        approveBtn.click();
                    }
                } else if (key === 's') {
                    e.preventDefault();
                    var skipBtn = document.querySelector('button[formaction*="skip"]');
                    if (skipBtn && !skipBtn.disabled) {
                        skipBtn.click();
                    }
                } else if (key === 'r') {
                    e.preventDefault();
                    var rejectBtn = document.querySelector('button[formaction*="reject"]');
                    if (rejectBtn && !rejectBtn.disabled) {
                        rejectBtn.click();
                    }
                }
            });
        },

        // Focal point clicking on image
        bindFocalPointClick: function() {
            var imgContainer = document.getElementById('lens-image-container');
            if (!imgContainer) return;

            var self = this;
            imgContainer.addEventListener('click', function(e) {
                var img = imgContainer.querySelector('img');
                if (!img) return;

                var rect = img.getBoundingClientRect();
                var x = (e.clientX - rect.left) / rect.width;
                var y = (e.clientY - rect.top) / rect.height;

                // Clamp to 0-1 range
                x = Math.max(0, Math.min(1, x));
                y = Math.max(0, Math.min(1, y));

                // Update hidden inputs
                var focalXInput = document.getElementById('focal-x-input');
                var focalYInput = document.getElementById('focal-y-input');
                if (focalXInput) focalXInput.value = x.toFixed(4);
                if (focalYInput) focalYInput.value = y.toFixed(4);

                // Update marker position
                var marker = document.getElementById('lens-focal-marker');
                if (marker) {
                    marker.style.left = (x * 100) + '%';
                    marker.style.top = (y * 100) + '%';
                    marker.style.display = 'block';
                }
            });
        },

        // Zoom controls
        bindZoomControls: function() {
            var self = this;
            var zoomInBtn = document.getElementById('lens-zoom-in');
            var zoomOutBtn = document.getElementById('lens-zoom-out');
            var zoomFitBtn = document.getElementById('lens-zoom-fit');

            if (!zoomInBtn || !zoomOutBtn || !zoomFitBtn) return;

            zoomInBtn.addEventListener('click', function() {
                self.zoomLevel = Math.min(6, self.zoomLevel + 1);
                self.applyZoom();
            });

            zoomOutBtn.addEventListener('click', function() {
                self.zoomLevel = Math.max(0, self.zoomLevel - 1);
                self.applyZoom();
            });

            zoomFitBtn.addEventListener('click', function() {
                self.zoomLevel = 0;
                self.applyZoom();
            });
        },

        applyZoom: function() {
            var scale = this.ZOOM_STEPS[this.zoomLevel];
            var img = document.getElementById('lens-review-image');
            var zoomLevelSpan = document.getElementById('lens-zoom-level');

            if (img) {
                img.style.transform = scale === 1 ? '' : 'scale(' + scale + ')';
                img.style.transformOrigin = 'center center';
            }

            if (zoomLevelSpan) {
                zoomLevelSpan.textContent = Math.round(scale * 100) + '%';
            }
        },

        // People detection radio buttons
        initPeopleDetectionEditor: function() {
            var peopleRadios = document.querySelectorAll('input[data-control="people-mode-review"]');
            if (peopleRadios.length === 0) return;

            var self = this;
            peopleRadios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (!radio.checked) return;

                    var value = radio.value;
                    var containsPeople = false;
                    var faceCount = 0;

                    // Map radio value to field values
                    switch (value) {
                        case 'none':
                            containsPeople = false;
                            faceCount = 0;
                            break;
                        case 'no-faces':
                            containsPeople = true;
                            faceCount = 0;
                            break;
                        case '1':
                            containsPeople = true;
                            faceCount = 1;
                            break;
                        case '2':
                            containsPeople = true;
                            faceCount = 2;
                            break;
                        case '3-5':
                            containsPeople = true;
                            faceCount = 4; // Use midpoint
                            break;
                        case '6+':
                            containsPeople = true;
                            faceCount = 6; // Use minimum
                            break;
                    }

                    // Update hidden inputs
                    var containsPeopleInput = document.getElementById('field-containsPeople');
                    var faceCountInput = document.getElementById('field-faceCount');
                    if (containsPeopleInput) containsPeopleInput.value = containsPeople ? '1' : '0';
                    if (faceCountInput) faceCountInput.value = faceCount.toString();
                });
            });
        },

        // Form submission - collect all field values
        bindFormSubmit: function() {
            var form = document.getElementById('lens-review-form');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                // Collect text field values
                var textFields = document.querySelectorAll('.lens-review-editable');
                textFields.forEach(function(field) {
                    var fieldName = field.getAttribute('data-field');
                    if (!fieldName) return;

                    var hiddenInput = document.getElementById('field-' + fieldName);
                    if (hiddenInput) {
                        hiddenInput.value = field.value;
                    }
                });

                // Tags are handled by taxonomy section (if exists)
                // Colors are handled by taxonomy section (if exists)
                // Focal point is already set by click handler
                // People detection is already set by radio change handler

                // Let form submit normally
            });
        }
    };

    // ==========================================================================
    // Auto-init on page load
    // ==========================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            LensReviewView.init();
        });
    } else {
        LensReviewView.init();
    }

})();

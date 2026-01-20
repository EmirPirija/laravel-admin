// Parsley Multi-Language Support with trans() function
// This file should be loaded after parsley.min.js and function.js
// It automatically applies translations without needing to call any methods

(function() {
    'use strict';
    
    // Wait for Parsley to be available
    if (typeof window.Parsley === 'undefined') {
        console.error('Parsley library not found. Make sure parsley.min.js is loaded before this file.');
        return;
    }
    
    // Ensure trans function exists
    if (typeof trans === 'undefined') {
        window.trans = function(label) {
            return (window.languageLabels && window.languageLabels[label]) ? window.languageLabels[label] : label;
        };
    }
    
    // Translation key mapping for Parsley messages
    var parsleyMessageKeys = {
        'defaultMessage': 'This value seems to be invalid.',
        'type.email': 'This value should be a valid email.',
        'type.url': 'This value should be a valid url.',
        'type.number': 'This value should be a valid number.',
        'type.integer': 'This value should be a valid integer.',
        'type.digits': 'This value should be digits.',
        'type.alphanum': 'This value should be alphanumeric.',
        'notblank': 'This value should not be blank.',
        'required': 'This value is required.',
        'pattern': 'This value seems to be invalid.',
        'min': 'This value should be greater than or equal to %s.',
        'max': 'This value should be lower than or equal to %s.',
        'range': 'This value should be between %s and %s.',
        'minlength': 'This value is too short. It should have %s characters or more.',
        'maxlength': 'This value is too long. It should have %s characters or fewer.',
        'length': 'This value length is invalid. It should be between %s and %s characters long.',
        'mincheck': 'You must select at least %s choices.',
        'maxcheck': 'You must select %s choices or fewer.',
        'check': 'You must select between %s and %s choices.',
        'equalto': 'This value should be the same.',
        'euvatin': 'It\'s not a valid VAT Identification Number.',
        // Custom validators
        'restrictedCity': 'You have to live in <a href="https://www.google.com/maps/place/Jakarta">Jakarta</a>.',
        'uppercase': 'Your password must contain at least (%s) uppercase letter.',
        'lowercase': 'Your password must contain at least (%s) lowercase letter.',
        'number': 'Your password must contain at least (%s) number.',
        'special': 'Your password must contain at least (%s) special characters.',
        'minSelect': 'You must select at least %s.',
        'notequalto': 'This value should not be the same.',
        'gt': 'This value should be greater %s',
        'ge': 'This value should be greater or equal ',
        'lt': 'This value should be less %s',
        'le': 'This value should be less or equal'
    };
    
    // Handle HTML in messages (like <br> tags)
    function processMessage(message) {
        // If message contains HTML, preserve it
        return message;
    }
    
    // Function to get translated message dynamically
    function getTranslatedMessage(assert) {
        var messageKey = assert.name;
        var requirements = assert.requirements;
        
        // Handle type assertions
        if (assert.name === 'type') {
            messageKey = 'type.' + requirements;
        }
        
        // Get the translation key
        var translationKey = parsleyMessageKeys[messageKey];
        
        // If no mapping found, try the messageKey itself
        if (!translationKey) {
            translationKey = messageKey;
        }
        
        // Get translated message using trans() function (called at runtime)
        var translatedMessage = trans(translationKey);
        
        // If translation not found, use the key as fallback
        if (translatedMessage === translationKey && parsleyMessageKeys[messageKey]) {
            translatedMessage = parsleyMessageKeys[messageKey];
        }
        
        // Replace %s placeholders with requirements
        if (requirements !== undefined && requirements !== null) {
            if (Array.isArray(requirements)) {
                requirements.forEach(function(req) {
                    translatedMessage = translatedMessage.replace('%s', req);
                });
            } else if (typeof requirements === 'object') {
                // Handle object requirements
                for (var prop in requirements) {
                    if (requirements.hasOwnProperty(prop)) {
                        translatedMessage = translatedMessage.replace('%s', requirements[prop]);
                    }
                }
            } else {
                // Single requirement value
                translatedMessage = translatedMessage.replace('%s', requirements);
            }
        }
        
        // Process message (handle HTML, etc.)
        translatedMessage = processMessage(translatedMessage);
        
        return translatedMessage;
    }
    
    // Override Parsley's getErrorMessage method to use trans() dynamically
    var originalGetErrorMessage = window.Parsley._validatorRegistry.getErrorMessage;
    window.Parsley._validatorRegistry.getErrorMessage = function(assert) {
        // Get translated message dynamically (trans() is called at runtime)
        var translatedMessage = getTranslatedMessage(assert);
        
        // Return translated message
        return translatedMessage;
    };
    
    // Override formatMessage to use trans() for any remaining messages
    var originalFormatMessage = window.Parsley._validatorRegistry.formatMessage;
    window.Parsley._validatorRegistry.formatMessage = function(template, requirements) {
        // If template exists in languageLabels, translate it
        if (typeof template === 'string' && window.languageLabels && window.languageLabels[template]) {
            template = trans(template);
        }
        
        // Use original formatMessage for placeholder replacement
        return originalFormatMessage.call(this, template, requirements);
    };
    
    // Initialize base messages (these are fallbacks, getErrorMessage override handles translations)
    function initializeParsleyMessages() {
        // Add all messages to Parsley catalog (fallback messages)
        window.Parsley.addMessages('en', {
            defaultMessage: 'This value seems to be invalid.',
            type: {
                email: 'This value should be a valid email.',
                url: 'This value should be a valid url.',
                number: 'This value should be a valid number.',
                integer: 'This value should be a valid integer.',
                digits: 'This value should be digits.',
                alphanum: 'This value should be alphanumeric.'
            },
            notblank: 'This value should not be blank.',
            required: 'This value is required.',
            pattern: 'This value seems to be invalid.',
            min: 'This value should be greater than or equal to %s.',
            max: 'This value should be lower than or equal to %s.',
            range: 'This value should be between %s and %s.',
            minlength: 'This value is too short. It should have %s characters or more.',
            maxlength: 'This value is too long. It should have %s characters or fewer.',
            length: 'This value length is invalid. It should be between %s and %s characters long.',
            mincheck: 'You must select at least %s choices.',
            maxcheck: 'You must select %s choices or fewer.',
            check: 'You must select between %s and %s choices.',
            equalto: 'This value should be the same.',
            euvatin: 'It\'s not a valid VAT Identification Number.'
        });
        
        window.Parsley.setLocale('en');
    }
    
    // Initialize messages
    initializeParsleyMessages();
    
    // Function to update messages when language changes (optional, for manual language switching)
    window.updateParsleyMessages = function() {
        // Messages are now dynamic, so no need to update
        // The getErrorMessage override handles translations automatically
    };
})();

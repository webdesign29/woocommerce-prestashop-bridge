(function () {
    'use strict';
    window.InkluraSyncI18n = {
        t: function (message) {
            var messages = window.InkluraSyncMessages || {};
            return Object.prototype.hasOwnProperty.call(messages, message) ? messages[message] : message;
        }
    };
})();

(function($) {
    var options;
    var app = {
        init: function() {
            this.cacheElements();
            this.bindEvents();
        },
        $: function(selector) {
            return this.$el.find(selector);
        },
        cacheElements: function() {
            this.$el = $('#message-js');
            this.$batch = this.$('.message-js-batch');
            this.$select = this.$('.message-batch-action');
        },
        bindEvents: function() {
            this.$batch.click(this.checkedAll);
            this.$select.change(this.batchAction);
        },
        checkedAll: function() {
            //Note: if you donot bind this, you must use app
            app.$('.message-js-check').attr('checked', app.$batch.prop('checked'));
        },
        batchAction: function() {
            var checked = app.$('.message-js-check:checked');
            var action = app.$select.val().trim();
            var ids = [];
            if (checked.length) {
                checked.each(function() {
                    ids.push($(this).attr('data-id'));
                });
                var url = options.host + "index/" + action + "/ids-" + ids;
                if (options.p) {
                    location.href = url + '/p-' + options.p;
                } else {
                    location.href = url;
                }
            } else {
                app.$select.attr('value', '');
            }
        }
    };

    this.messageIndex = function(opts) {
        options = opts || {};
        app.init();
    };
})(jQuery);
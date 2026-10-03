<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var toast = window.xcToast || function() {};

        $(function() {
            // Numeric-only filter for the tuning inputs.
            $.fn.inputFilter = function(cb) {
                return this.on('input keydown keyup mousedown mouseup select contextmenu drop', function() {
                    if (cb(this.value)) {
                        this.oldValue = this.value;
                        this.oldSelectionStart = this.selectionStart;
                        this.oldSelectionEnd = this.selectionEnd;
                    } else if (this.hasOwnProperty('oldValue')) {
                        this.value = this.oldValue;
                        this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                    }
                });
            };
            var digits = function(value) {
                return /^\d*$/.test(value);
            };
            $('#scan_seconds').inputFilter(digits);
            $('#max_items').inputFilter(digits);

            // Save → the module's settings_watch_save action (same JSON contract as post.php had).
            $('#watch-settings-form').on('submit', function(e) {
                e.preventDefault();
                var btn = document.getElementById('save-settings');
                if (btn) {
                    btn.disabled = true;
                }
                var fd = new FormData(this);
                fd.append('submit_settings', '1');
                fetch('./api?action=settings_watch_save', {
                        method: 'POST',
                        body: fd,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.text();
                    })
                    .then(function(txt) {
                        var dt;
                        try {
                            dt = JSON.parse(txt);
                        } catch (err) {
                            dt = {
                                result: false
                            };
                        }
                        if (dt && dt.result !== false) {
                            window.location.href = dt.location || 'settings_watch';
                            return;
                        }
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast('Failed to save Watch settings.', 'error');
                    })
                    .catch(function() {
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast('Failed to save Watch settings.', 'error');
                    });
            });
        });
    })();
</script>
</body>

</html>

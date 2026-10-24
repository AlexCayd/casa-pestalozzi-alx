(function () {
    document.addEventListener('change', function (event) {
        var select = event.target;
        if (select.matches('[data-context-select]') && !select.disabled) {
            select.form.requestSubmit();
        }
    });
})();

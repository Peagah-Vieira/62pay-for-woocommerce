(function ($) {
    $(document).on('input', '#wc-62pay-cc-expiry', function () {
        let v = $(this).val().replace(/[^0-9]/g, '').slice(0, 4);
        if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2);
        $(this).val(v);
    });
})(jQuery);

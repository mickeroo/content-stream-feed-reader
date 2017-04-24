/**
 * Created by mike on 4/24/17.
 */
(function($) {

    $('#cron_enabled').on('click', function() {

        if ( $('#cron_enabled').is(':checked')) {
            $('#cs_cron_start').prop('disabled', false);
            $('#cs_cron_freq').prop('disabled', false);
        } else {
            $('#cs_cron_start').prop('disabled', true);
            $('#cs_cron_freq').prop('disabled', true);
        }
    });

    $('#cs_cron_start').datepicker({
        dateFormat: 'yy-mm-dd',
    });

})( jQuery );
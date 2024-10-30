jQuery(function($){

    /* CartCatch -
                    Capture emails to reach out to customers who abandon their carts.
                    Customers can easily opt out using 'unsubscribe' in the email.
                    https://www.cartcatch.com
     */

    var $eml = $("#billing_email");

    var cartCatch = {
        validateEmail: function(email) {
            var re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(email);
        },
        doNotTrackEnabled: function(){
            if (window.doNotTrack || navigator.doNotTrack || navigator.msDoNotTrack || 'msTrackingProtectionEnabled' in window.external) {

                // The browser supports Do Not Track!

                if (window.doNotTrack == "1" || navigator.doNotTrack == "yes" || navigator.doNotTrack == "1" || navigator.msDoNotTrack == "1" || window.external.msTrackingProtectionEnabled()) {

                    // Do Not Track is enabled!
                    return true;
                }

            }
            return false;
        }
    };


    var tryCaptureEmail = function(){
        try{
            var email = $eml.val();

            if(cartCatch.validateEmail(email) && !cartCatch.doNotTrackEnabled()){
                $.post("/wp-admin/admin-ajax.php?action=capture_cartcatch_email", {
                    email: email,
                    time: (+new Date())
                }, function (s) {
                });
            }
        }
        catch(e){
            // if there's an error, we do not want to cause issues on checkout.
        }


    }
    $eml.on("keyup", tryCaptureEmail);
    tryCaptureEmail();

});
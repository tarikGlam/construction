$(document).ready(function () {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });
});

$("#payCancelBtn").click(function(){
    SaleProConfirm.open({ message: 'Are you sure to cancel ?' }).then(function(confirmed) { if (confirmed) {
        $.ajax({
            url: cancelURL,
            type: 'POST',
            data: {},
            dataType: 'JSON',
            success: function (data) {
                if(data='success'){
                    window.location.href = redirectURLAfterCancel;
                }
            }
        });
    }
});

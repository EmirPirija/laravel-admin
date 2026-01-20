<script>
    const urlParams = new URLSearchParams(window.location.search);

    const trxref = urlParams.get('trxref');
    const reference = urlParams.get('reference');

    window.opener.postMessage({
        status: 'success',
        reference: reference || 'your-payment-reference',
        trxref: trxref
    },  '*');
    window.close();
</script>


<script>
    const urlParams = new URLSearchParams(window.location.search);

    const trxref = @json($trxref);
    const reference = @json($reference);


    window.opener.postMessage({
        status: 'success',
        reference: reference || 'your-payment-reference', // Use extracted reference, fallback if missing
        trxref: trxref
    }, '*');
    window.close();
</script>


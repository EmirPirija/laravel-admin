<script>
    const urlParams = new URLSearchParams(window.location.search);

    const trxref = urlParams.get('trxref');
    const reference = urlParams.get('reference');

    window.opener.postMessage({
        status: 'success',
        reference: reference || 'your-payment-reference', // Use extracted reference, fallback if missing
        trxref: trxref
    }, '*');
    window.close();
    </script>


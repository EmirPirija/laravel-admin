<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open in App</title>
    @include('layouts.include')
    <style>
        .bottom-sheet {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #fff;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            transform: translateY(100%);
            transition: transform 0.3s ease-out;
            z-index: 1050;
        }

        .bottom-sheet.show {
            transform: translateY(0);
        }

        /* @media (min-width: 1025px) {
            .bottom-sheet {
                display: none;
            }
        } */
    </style>
</head>
<body>

@include('layouts.footer_script')
<script>
$(document).ready(function () {
    let appScheme = '{{ $scheme }}://' + window.location.host + window.location.pathname;
    let androidAppStoreLink = '{{ $playStoreLink }}';
    let iosAppStoreLink = '{{ $appStoreLink }}';
    let userAgent = navigator.userAgent || navigator.vendor || window.opera;
    let isAndroid = /android/i.test(userAgent);
    let isIOS = /iPad|iPhone|iPod/.test(userAgent) && !window.MSStream;
    let appStoreLink = isAndroid ? androidAppStoreLink : (isIOS ? iosAppStoreLink : androidAppStoreLink);

    window.location.href = appScheme;

    setTimeout(function () {
        if (!document.hidden && !document.webkitHidden) {
            if (confirm("{{$appName}} app is not installed. Would you like to download it from the app store?")) {
                window.location.href = appStoreLink;
            }
        }
    }, 1000);
});
</script>

</body>
</html>

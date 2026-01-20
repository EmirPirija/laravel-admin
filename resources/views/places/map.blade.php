<!DOCTYPE html>
<html lang="en">
<head>
    <title>OpenLayers Map</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol/ol.css" />
    <script src="https://cdn.jsdelivr.net/npm/ol/ol.js"></script>
    <style>
        #map {
            height: 500px;
            width: 100%;
        }
    </style>
</head>
<body>

<div id="map"></div>

<form action="{{ route('store.location') }}" method="POST">
    @csrf
    <label>Latitude:</label>
    <input type="text" id="latitude" name="latitude" readonly>

    <label>Longitude:</label>
    <input type="text" id="longitude" name="longitude" readonly>

    <button type="submit">Save Location</button>
</form>

<script>
    const map = new ol.Map({
        target: 'map',
        layers: [
            new ol.layer.Tile({
                source: new ol.source.OSM() // OpenStreetMap Layer
            })
        ],
        view: new ol.View({
            center: ol.proj.fromLonLat([0, 0]),
            zoom: 2
        })
    });

    let marker = new ol.Overlay({
        position: ol.proj.fromLonLat([0, 0]),
        element: document.createElement('div'),
        positioning: 'center-center'
    });
    map.addOverlay(marker);

    map.on('click', function(event) {
        const coordinate = ol.proj.toLonLat(event.coordinate);
        const lat = coordinate[1];
        const lng = coordinate[0];

        document.getElementById('latitude').value = lat;
        document.getElementById('longitude').value = lng;

        marker.setPosition(event.coordinate);
    });
</script>

</body>
</html>

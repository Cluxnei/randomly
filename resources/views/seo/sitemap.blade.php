<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
{{-- Built from the registry, so a generator added to config/randomly.php is in
     here on the next request without anybody remembering to add it. --}}<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
        <priority>{{ $url['priority'] }}</priority>
    </url>
@endforeach
</urlset>

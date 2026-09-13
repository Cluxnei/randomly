{{-- The seed in the footer is the feature, not the decoration: a sheet you
     cannot regenerate from the paper in your hand is not reproducible in any way
     that helps the person holding it. --}}
<footer class="colophon">
    <span>{{ $page }}</span>
    <span>seed <span class="seed">{{ $generation->seed->token() }}</span></span>
    <span>{{ $receipt->sourceLabel }}</span>
    <span>{{ $permalink }}</span>
</footer>

<?php
require __DIR__.'/vendor/autoload.php';

use App\Support\Brand\Contrast as C;

print_r(C::surface('#E05614'));
$t = C::asText('#E05614', '#FFFDF8');
echo "asText: $t ".C::ratio($t, '#FFFDF8')."\n";
echo 'orange/white '.C::ratio('#E05614', '#FFFFFF').' orange/ink '.C::ratio('#E05614', '#0F2A44')."\n";
foreach (['#F8F4EA', '#FFFDF8', '#0F2A44', '#163756'] as $g) {
    echo "orange on $g ".C::ratio('#E05614', $g)."\n";
}
$c = '#E05614';
for ($i = 0; $i < 30 && C::ratio('#FFFFFF', $c) < 4.5; $i++) {
    $c = C::mix($c, '#000000', .04);
}
echo "darkened+white: $c ".C::ratio('#FFFFFF', $c)."\n";

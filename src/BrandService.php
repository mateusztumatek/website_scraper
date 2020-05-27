<?php

namespace Mateusz\WebsiteScraper;

use Goutte\Client;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Symfony\Component\CssSelector\CssSelectorConverter;

class BrandService{
    public $domains = ['pl', 'com', 'org', 'de', 'eu', 'fr'];
    protected $scraped = null;
    public function getInfo($website){
        $logo = $this->getLogo($this->getDomain($website, false));
        $colors = $this->getMainColors($website);
        return ['colors' => $colors, 'logo' => $logo];
    }
    public function scrapSiteContent($website){
        $client = new Client();
        $request = $client->request('GET', $website);
        $domain = $this->getDomain($website, false);
        $styles = collect();
        $request->filterXPath('//*[contains(@rel, "stylesheet")]')->each(function ($item)use($domain, $styles, $website){
            $href = $item->attr('href');
            if($href[0] == '/') $href = $website.$href;
            if($href){
                $tmp = new Client();
                try{
                    $style = file_get_contents($href, true);
                    $styles->push($style);
                }catch (\Exception $e){
                }
            }
        });
        $text = $request->html();
        foreach ($styles as $style){
            $text = $text.' '.$style;
        }
        return $text;
    }
    public function getLogo($domain){
        $q = $domain.' logo';
        if(!Storage::exists('/scraped')) Storage::makeDirectory('scraped', 666);
        str_replace('http://', '', $q);
        str_replace('https://', '', $q);
        $client = new \GuzzleHttp\Client();
        $res = $client->request('GET', 'https://www.googleapis.com/customsearch/v1', [
            'query' => [
                'key' => 'AIzaSyDoTGwnK5xm2GVuj3oE-94X3sdNDkHNwbs',
                'cx' => '007042010266084364640:4k2bdmk9lkg',
                'searchType' => 'image',
                'q' => $q
            ]
        ]);
        $res = $res->getBody();
        $res = $res->getContents();
        $data = json_decode($res);
        $col = collect();
        foreach ($data->items as $item){
            if(property_exists($item, 'fileFormat') && ($item->fileFormat == 'image/png' || $item->fileFormat == 'image/jpeg')){
                $logo = $item->link;
                $extension = File::extension($logo);
                if(Str::contains(Str::lower($logo), 'logo')){
                    $proposed = true;
                }else{
                    $proposed = false;
                }
                if(Str::contains($extension , 'png') || Str::contains($extension , 'svg') || $proposed){
                    $col->prepend((object) ['url' => $logo, 'extension' => $extension, 'proposed' => $proposed]);
                }else{
                    $col->push((object) ['url' => $logo, 'extension' => $extension, 'proposed' => $proposed]);
                }
            }
        }
        $col = $col->sortByDesc('proposed');
        if($col->count() == 0) return null;
        $to_return = [];
        foreach ($col as $item){
            try{
                if(count($to_return) == 3){
                    break;
                }
                $filename = basename($item->url);
                $image = file_get_contents($item->url);
                Storage::put('/scraped/'.$filename, $image);
                array_push($to_return, Storage::url('/scraped/'.$filename));
            }catch(\Exception $e){
            }
        }
        return $to_return;

    }
    public function getMainColors($website){
        if(!$this->scraped){
            $this->scraped = $this->scrapSiteContent($website);
            $text = $this->scraped;
        }else{
            $text = $this->scraped;
        }
        preg_match_all('/(color)\:?.#\w+/i', $text, $bg_matches);
        $bg_matches = array_map(function ($item){
            $tmp = $item;
            $tmp = Str::replaceFirst('color:', '', $tmp);
            $tmp = Str::replaceFirst(';', '', $tmp);
            $tmp = Str::replaceFirst(' ', '', $tmp);
            return $tmp;
        }, $bg_matches[0]);
        $bg_collect = collect();
        foreach ($bg_matches as $elem){
            $bg_collect->push((object) ['value' => $elem]);
        }
        $bg_collect = $bg_collect->groupBy('value');
        $bg_collect = $bg_collect->map(function($item, $index){
            return (object) ['color' => $index, 'count' => $item->count()];
        });
        foreach ($bg_collect as $key => $item){
            $rgb = $this->hexToRgb($item->color);
            try{
                $lightness = $this->RGBToHSL($this->HTMLToRGB($item->color))->lightness;
            }catch(\Exception $e){
                $lightness = 100;
            }
            if(($rgb['r'] == $rgb['g'] && $rgb['r'] == $rgb['b']) || ($lightness > 230 || $lightness < 10)){
                $bg_collect->forget($key);
            }
        }
        $bg_collect = $bg_collect->sortByDesc('count')->values()->take(3);
        return $bg_collect;
    }
    public function getDomain($website, $withType = true){
        $w = Str::replaceFirst('https://', '', $website);
        $w =  Str::replaceFirst('http://', '', $w);
        $w =  Str::replaceFirst('wwww.', '', $w);
        $expolded = explode('/', $w);
        if($withType){
            return $expolded[0];
        }else{
            $tmp = $expolded[0];
            foreach ($this->domains as $d){
                $tmp = Str::replaceFirst('.'.$d, '', $tmp);
            }
            return $tmp;
        }

    }

    function hexToRgb($hex, $alpha = false) {
        $hex      = str_replace('#', '', $hex);
        $length   = strlen($hex);
        $rgb['r'] = hexdec($length == 6 ? substr($hex, 0, 2) : ($length == 3 ? str_repeat(substr($hex, 0, 1), 2) : 0));
        $rgb['g'] = hexdec($length == 6 ? substr($hex, 2, 2) : ($length == 3 ? str_repeat(substr($hex, 1, 1), 2) : 0));
        $rgb['b'] = hexdec($length == 6 ? substr($hex, 4, 2) : ($length == 3 ? str_repeat(substr($hex, 2, 1), 2) : 0));
        if ( $alpha ) {
            $rgb['a'] = $alpha;
        }
        return $rgb;
    }
    public function HTMLToRGB($htmlCode)
    {
        if($htmlCode[0] == '#')
            $htmlCode = substr($htmlCode, 1);

        if (strlen($htmlCode) == 3)
        {
            $htmlCode = $htmlCode[0] . $htmlCode[0] . $htmlCode[1] . $htmlCode[1] . $htmlCode[2] . $htmlCode[2];
        }

        $r = hexdec($htmlCode[0] . $htmlCode[1]);
        $g = hexdec($htmlCode[2] . $htmlCode[3]);
        $b = hexdec($htmlCode[4] . $htmlCode[5]);

        return $b + ($g << 0x8) + ($r << 0x10);
    }

    public function RGBToHSL($RGB) {
        $r = 0xFF & ($RGB >> 0x10);
        $g = 0xFF & ($RGB >> 0x8);
        $b = 0xFF & $RGB;

        $r = ((float)$r) / 255.0;
        $g = ((float)$g) / 255.0;
        $b = ((float)$b) / 255.0;

        $maxC = max($r, $g, $b);
        $minC = min($r, $g, $b);

        $l = ($maxC + $minC) / 2.0;

        if($maxC == $minC)
        {
            $s = 0;
            $h = 0;
        }
        else
        {
            if($l < .5)
            {
                $s = ($maxC - $minC) / ($maxC + $minC);
            }
            else
            {
                $s = ($maxC - $minC) / (2.0 - $maxC - $minC);
            }
            if($r == $maxC)
                $h = ($g - $b) / ($maxC - $minC);
            if($g == $maxC)
                $h = 2.0 + ($b - $r) / ($maxC - $minC);
            if($b == $maxC)
                $h = 4.0 + ($r - $g) / ($maxC - $minC);

            $h = $h / 6.0;
        }

        $h = (int)round(255.0 * $h);
        $s = (int)round(255.0 * $s);
        $l = (int)round(255.0 * $l);

        return (object) Array('hue' => $h, 'saturation' => $s, 'lightness' => $l);
    }
}
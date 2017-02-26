<?php
/**
 * PHP Implementation of MurmurHash3 (modified for wow_ah)
 *
 * @author Stefano Azzolini (lastguest@gmail.com)
 * @see https://github.com/lastguest/murmurhash-php
 * @author Gary Court (gary.court@gmail.com)
 * @see http://github.com/garycourt/murmurhash-js
 * @author Austin Appleby (aappleby@gmail.com)
 * @see http://sites.google.com/site/murmurhash/
 *
 * @param  string $key   Text to hash.
 * @param  number $seed  Positive integer only
 * @return number 32-bit (base 32 converted) positive integer hash
 *
 * Copyright (c) 2011 Stefano Azzolini
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

define("BASE64ENC", "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/");

function murmurhash3_base64($key) {
  return base64_encode_int(murmurhash3($key));
}

function base64_encode_int($int,$length=0) {
    $output = '';
    $power = 1;
    do {
        $power *= 64;
        $substract = $int%$power;
        $output = BASE64ENC[(int)($substract/($power/64))] . $output;
    } while($int/$power >= 1);
    
    if($length) {
        $output = substr(str_pad($output,$length,BASE64ENC[0],STR_PAD_LEFT),-$length);
    }
    
    return $output;
}

function murmurhash3($key,$seed=0){
  $key  = array_values(unpack('C*',(string) $key));
  $klen = count($key);
  $h1   = (int)$seed;
  for ($i=0,$bytes=$klen-($remainder=$klen&3) ; $i<$bytes ; ) {
    $k1 = $key[$i]
      | ($key[++$i] << 8)
      | ($key[++$i] << 16)
      | ($key[++$i] << 24);
    ++$i;
    $k1  = (((($k1 & 0xffff) * 0xcc9e2d51) + ((((($k1 >= 0 ? $k1 >> 16 : (($k1 & 0x7fffffff) >> 16) | 0x8000)) * 0xcc9e2d51) & 0xffff) << 16))) & 0xffffffff;
    $k1  = $k1 << 15 | ($k1 >= 0 ? $k1 >> 17 : (($k1 & 0x7fffffff) >> 17) | 0x4000);
    $k1  = (((($k1 & 0xffff) * 0x1b873593) + ((((($k1 >= 0 ? $k1 >> 16 : (($k1 & 0x7fffffff) >> 16) | 0x8000)) * 0x1b873593) & 0xffff) << 16))) & 0xffffffff;
    $h1 ^= $k1;
    $h1  = $h1 << 13 | ($h1 >= 0 ? $h1 >> 19 : (($h1 & 0x7fffffff) >> 19) | 0x1000);
    $h1b = (((($h1 & 0xffff) * 5) + ((((($h1 >= 0 ? $h1 >> 16 : (($h1 & 0x7fffffff) >> 16) | 0x8000)) * 5) & 0xffff) << 16))) & 0xffffffff;
    $h1  = ((($h1b & 0xffff) + 0x6b64) + ((((($h1b >= 0 ? $h1b >> 16 : (($h1b & 0x7fffffff) >> 16) | 0x8000)) + 0xe654) & 0xffff) << 16));
  }
  $k1 = 0;
  switch ($remainder) {
    case 3: $k1 ^= $key[$i + 2] << 16;
    case 2: $k1 ^= $key[$i + 1] << 8;
    case 1: $k1 ^= $key[$i];
    $k1  = ((($k1 & 0xffff) * 0xcc9e2d51) + ((((($k1 >= 0 ? $k1 >> 16 : (($k1 & 0x7fffffff) >> 16) | 0x8000)) * 0xcc9e2d51) & 0xffff) << 16)) & 0xffffffff;
    $k1  = $k1 << 15 | ($k1 >= 0 ? $k1 >> 17 : (($k1 & 0x7fffffff) >> 17) | 0x4000);
    $k1  = ((($k1 & 0xffff) * 0x1b873593) + ((((($k1 >= 0 ? $k1 >> 16 : (($k1 & 0x7fffffff) >> 16) | 0x8000)) * 0x1b873593) & 0xffff) << 16)) & 0xffffffff;
    $h1 ^= $k1;
  }
  $h1 ^= $klen;
  $h1 ^= ($h1 >= 0 ? $h1 >> 16 : (($h1 & 0x7fffffff) >> 16) | 0x8000);
  $h1  = ((($h1 & 0xffff) * 0x85ebca6b) + ((((($h1 >= 0 ? $h1 >> 16 : (($h1 & 0x7fffffff) >> 16) | 0x8000)) * 0x85ebca6b) & 0xffff) << 16)) & 0xffffffff;
  $h1 ^= ($h1 >= 0 ? $h1 >> 13 : (($h1 & 0x7fffffff) >> 13) | 0x40000);
  $h1  = (((($h1 & 0xffff) * 0xc2b2ae35) + ((((($h1 >= 0 ? $h1 >> 16 : (($h1 & 0x7fffffff) >> 16) | 0x8000)) * 0xc2b2ae35) & 0xffff) << 16))) & 0xffffffff;
  $h1 ^= ($h1 >= 0 ? $h1 >> 16 : (($h1 & 0x7fffffff) >> 16) | 0x8000);
  return $h1;
}
?>
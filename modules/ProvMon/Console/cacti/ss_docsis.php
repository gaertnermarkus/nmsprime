<?php

/* do NOT run this script through a web browser */
if (! isset($_SERVER['argv'][0]) || isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['REMOTE_ADDR'])) {
    die('<br><strong>This script is only meant to run at the command line.</strong>');
}

/* display no errors */
error_reporting(0);

if (! isset($called_by_script_server)) {
    include_once dirname(__FILE__).'/../include/global.php';

    echo call_user_func('ss_docsis');
}

$snrs = [];
foreach (glob('/var/www/nmsprime/storage/app/data/provmon/us_snr/*.json') as $file) {
    $snrs = array_merge($snrs, json_decode(file_get_contents($file), true));
}
$GLOBALS['snrs'] = $snrs;

function ss_docsis_stats($a, $name)
{
    if (empty($a)) {
        return [
            'min'.$name => null,
            'avg'.$name => null,
            'max'.$name => null,
        ];
    }

    return [
        'min'.$name => min($a),
        'avg'.$name => array_sum($a) / count($a),
        'max'.$name => max($a),
    ];
}

function ss_docsis_snmp($host, $com, $oid, $denom = null)
{
    try {
        $ret = snmp2_walk($host, $com, $oid);
        if ($ret === false) {
            throw new Exception('No value using SNMP v2.');
        }
    } catch (\Exception $e) {
        try {
            $ret = snmpwalk($host, $com, $oid);
        } catch (\Exception $e) {
            return;
        }
    }

    if ($ret === false) {
        $ret = snmpwalk($host, $com, $oid);
    }

    if ($denom) {
        return array_map(function ($val) use ($denom) {
            return $val / $denom;
        }, $ret);
    }

    return $ret;
}

function ss_docsis($hostname, $snmp_community)
{
    snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
    try {
        // 1: D1.0, 2: D1.1, 3: D2.0, 4: D3.0
        $ver = snmpget($hostname, $snmp_community, '1.3.6.1.2.1.10.127.1.1.5.0');
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Error in packet at') !== false) {
            $ver = 1;
        } else {
            return;
        }
    }

    $ds['Pow'] = ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.1.1.1.6', 10);
    $ds['MuRef'] = ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.1.4.1.6');
    if ($ver >= 4) {
        $ds['SNR'] = ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.4.1.4491.2.1.20.1.24.1.1', 10);
        $us['Pow'] = ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.4.1.4491.2.1.20.1.2.1.1', 10);
    } else {
        $ds['SNR'] = ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.1.4.1.5', 10);
        $us['Pow'] = ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.2.2.1.3.2', 10);
    }
    $us['SNR'] = $GLOBALS['snrs'][gethostbyname($hostname)];

    foreach ($ds['Pow'] as $key => $val) {
        if ($ds['SNR'][$key] == 0) {
            foreach ($ds as $entry => $arr) {
                unset($ds[$entry][$key]);
            }
        }
    }
    if ($ver >= 4) {
        foreach (ss_docsis_snmp($hostname, $snmp_community, '1.3.6.1.4.1.4491.2.1.20.1.2.1.9') as $key => $val) {
            if ($val != 4) {
                foreach ($us as $entry => $arr) {
                    unset($us[$entry][$key]);
                }
            }
        }
    }

    $arr = array_merge(
        ss_docsis_stats($ds['Pow'], 'DsPow'),
        ss_docsis_stats($ds['SNR'], 'DsSNR'),
        ss_docsis_stats($ds['MuRef'], 'MuRef'),
        ss_docsis_stats($us['Pow'], 'UsPow'),
        ss_docsis_stats($us['SNR'], 'UsSNR'),
        ['T3Timeout' => array_sum(ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.2.2.1.12'))],
        ['T4Timeout' => array_sum(ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.2.2.1.13'))],
        ['Corrected' => array_sum(ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.1.4.1.3'))],
        ['Uncorrectable' => array_sum(ss_docsis_snmp($hostname, $snmp_community, '.1.3.6.1.2.1.10.127.1.1.4.1.4'))]
    );

    // pre-equalization-related data
    $file = "/usr/share/cacti/rra/$hostname.json";
    $rates = ['+8 hours', '+4 hours', '+10 minutes'];
    $preEqu = json_decode(file_exists($file) ? file_get_contents($file) : '{"rate":0}', true);

    // if (!isset($preEqu['next']) || time() > $preEqu['next']) {
        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_LIBRARY);

        $bandwidthOid = '.1.3.6.1.2.1.10.127.1.1.2.1.3';
        $preEquDataOid = '.1.3.6.1.2.1.10.127.1.2.2.1.17.2';
        $sysDescriptionOid = '.1.3.6.1.2.1.1.1';

        $bandwidth = ss_docsis_snmp($hostname, $snmp_community, $bandwidthOid);
        $preEquData = ss_docsis_snmp($hostname, $snmp_community, $preEquDataOid);
        $systemDescription = ss_docsis_snmp($hostname, $snmp_community, $sysDescriptionOid)[0];

        $preEqu['next'] = strtotime($rates[$preEqu['rate']]);
        $preEqu['width'] = $bandwidth ? reset($bandwidth) : 3200000;
        $preEqu['descr'] = isset($systemDescription) ? $systemDescription : 'n/a';

        $preEqu['raw'] = $preEquData ?  preg_replace("/[^A-Fa-f0-9]/", '', reset($preEquData)) : '';

        $freq = $preEqu['width'];
        $hexs = str_split($preEqu['raw'], 8);
        $or_hexs = array_shift($hexs);
        $maintap = 2 * $or_hexs[1] - 2;
        $energymain = $maintap / 2;
        array_splice($hexs, 0, 0);
        $hexs = implode('', $hexs);
        $hexs = str_split($hexs, 4);
        $hexcall = $hexs;
        $counter = 0;

        foreach ($hexs as $hex) {
            $hsplit = str_split($hex, 1);
            $counter++;
            if (is_numeric($hsplit[0]) && $hsplit[0] == 0 && $counter >= 46) {
                $decimal = _threenibble($hexcall);
                break;
            } elseif (ctype_alpha($hsplit[0]) || $hsplit[0] != 0 && $counter >= 46) {
                $decimal = _fournibble($hexcall);
                break;
            }
        }

        $pwr = _nePwr($decimal, $maintap);
        $ene = _energy($pwr, $maintap, $energymain);
        $fft = _fft($pwr);
        $tdr = _tdr($ene, $energymain, $freq);

        $preEqu['power'] = $pwr;
        $preEqu['energy'] = $ene;
        $preEqu['tdr'] = $tdr;
        $preEqu['max'] = $fft[1];
        $preEqu['fft'] = $fft[0];

        $arr['preEqualization'] = $preEqu;
        $arr['next'] = $preEqu['next'];
        $arr['width'] = $preEqu['width'];
        $arr['descr'] = $preEqu['descr'];

        file_put_contents($file, json_encode($preEqu));
    // }

    $result = '';
    foreach ($arr as $key => $value) {
        $result = is_numeric($value) || $key == 'descr' ? ($result.$key.':'.$value.' ') : ($result.$key.':NaN ');
    }

    return trim($result);
}

function _threenibble($hexcall)
{
    $ret = [];
    $counter = 0;

    foreach ($hexcall as $hex) {
        $counter++;
        if ($counter < 49) {
            $hex = str_split($hex, 1);
            if (ctype_alpha($hex[1]) || $hex[1] > 7) {
                $hex[0] = 'F';
                $hex = implode('', $hex);
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
                $hex = strrev("$hex");
                $dec = array_values(array_slice(unpack('s', pack('h*', "$hex")), -1))[0];
                array_push($ret, $dec);
            } else {
                $hex[0] = 0;
                $hex = implode('', $hex);
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
                $hex = strrev("$hex");
                $dec = array_values(array_slice(unpack('s', pack('h*', "$hex")), -1))[0];
                array_push($ret, $dec);
            }
        }
    }

    return $ret;
}

function _fournibble($hexcall)
{
    $ret = [];
    $counter = 0;

    foreach ($hexcall as $hex) {
        $counter++;
        if ($counter < 49) {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
            $hex = strrev("$hex");
            $dec = array_values(array_slice(unpack('s', pack('h*', "$hex")), -1))[0];
            array_push($ret, $dec);
        }
    }

    return $ret;
}

function _nePwr($decimal, $maintap)
{
    $pwr = [];
    $ans = implode('', array_keys($decimal, max($decimal)));
    if ($maintap == $ans) {
        $a2 = $decimal[$maintap];
        $b2 = $decimal[$maintap + 1];
        foreach (array_chunk($decimal, 2) as $val) {
            $a1 = $val[0];
            $b1 = $val[1];
            $pwr[] = ($a1 * $a2 - $b1 * $b2) / ($a2 ** 2 + $b2 ** 2);
            $pwr[] = ($a2 * $b1 + $a1 * $b2) / ($a2 ** 2 + $b2 ** 2);
        }
    } else {
        for ($i = 0; $i < 48; $i++) {
            $pwr[] = 0;
        }
    }

    return $pwr;
}

function _energy($pwr, $maintap, $energymain)
{
    $ene_db = [];
        //calculating the magnitude
    $pwr = array_chunk($pwr, 2);
    foreach ($pwr as $val) {
        $temp = 10 * log10($val[0] ** 2 + $val[1] ** 2);
        if (!(is_finite($temp))) {
            $temp = -100;
        }
        $ene_db[] = round($temp, 2);
    }

    return $ene_db;
}

function _tdr($ene, $energymain, $freq)
{
    if ($ene[$energymain] == -100) {
        $tdr = 0;
    } else {
        // propgagtion speed in cable networks (87% speed of light)
        $v = 0.87 * 299792458;
        unset($ene[$energymain]);
        $highest = array_keys($ene, max($ene));
        $highest = implode('', $highest);
        $tap_diff = abs($energymain - $highest);
        // 0.8 - Roll-off of filter; /2 -> round-trip (back and forth)
        $tdr = $v * $tap_diff / (0.8 * $freq) / 2;
        $tdr = round($tdr, 1);
    }

    return $tdr;
}

function _fft($pwr)
{
    $rea = [];
    $imag = [];
    $pwr = array_chunk($pwr, 2);
    foreach ($pwr as $val) {
        $rea[] = $val[0];
        $imag[] = $val[1];
    }

    for ($i = 0; $i < 104; $i++) {
        array_push($rea, 0);
        array_push($imag, 0);
    }

    for ($i = 0; $i < 248; $i++) {
        array_push($rea, array_shift($rea));
        array_push($imag, array_shift($imag));
    }

    require_once __DIR__. '/../../../../vendor/brokencube/fft/src/FFT.php';
    $ans = Brokencube\FFT\FFT::run($rea, $imag);
    ksort($ans[0]);
    ksort($ans[1]);
    for ($i = 0; $i < 64; $i++) {
        array_push($ans[0], array_shift($ans[0]));
        array_push($ans[1], array_shift($ans[1]));
    }

    $answer = array_map(function ($v1, $v2) {
        return 20 * log10(sqrt($v1 ** 2 + $v2 ** 2));
    }, $ans[0], $ans[1]);

        // stores the maximum amplitude value of the fft waveform
    $x = max($answer);
    $y = abs(min($answer));
    $maxamp = $x >= $y ? $x : $y;

    if (!(is_finite($maxamp))) {
        $maxamp = 0;
    }

    return [$answer, $maxamp];
}

<?php

namespace App\Support;

/** One validated CSS value per branding token, including legacy stored themes. */
final class BrandThemeTokens
{
    public const ALLOWED = [
        '--primary', '--primary-foreground',
        '--secondary', '--secondary-foreground',
        '--accent', '--accent-foreground',
        '--background', '--foreground',
        '--card', '--card-foreground',
        '--popover', '--popover-foreground',
        '--border', '--input', '--ring',
        '--sidebar', '--sidebar-foreground',
        '--sidebar-primary', '--sidebar-primary-foreground',
        '--sidebar-accent', '--sidebar-accent-foreground',
        '--sidebar-border', '--sidebar-ring',
        '--radius',
    ];

    private const NUMBER = '[+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:e[+-]?[0-9]+)?';

    private const NAMED_COLOURS = 'aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen transparent currentcolor';

    /** @return array<string, string> */
    public static function filter(mixed $vars): array
    {
        $out = [];
        foreach (is_array($vars) ? $vars : [] as $key => $value) {
            if (! in_array($key, self::ALLOWED, true) || ! is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value, " \t\r\n"));
            // Delimiters, escapes, comments and markup are never a colour/length.
            if ($value === '' || strlen($value) > 512 || preg_match('/[^a-z0-9#%().,+\-\/ \t\r\n]/', $value)) {
                continue;
            }
            $value = preg_replace('/\s+/', ' ', $value);
            if ($key === '--radius') {
                if (preg_match('/^(?:0|\+?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|rem|em|%|vh|vw|vmin|vmax|ch|ex))$/D', $value)) {
                    $out[$key] = $value;
                }

                continue;
            }
            // Older branding settings used bare HSL channels. Emit an actual colour.
            $hue = self::NUMBER.'(?:deg|grad|rad|turn)?';
            if (preg_match('/^'.$hue.' '.self::NUMBER.'% '.self::NUMBER.'%$/D', $value)) {
                $value = 'hsl('.$value.')';
            }
            if (self::isColour($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function css(mixed $vars): string
    {
        $css = '';
        foreach (self::filter($vars) as $key => $value) {
            $css .= $key.': '.$value.';';
        }

        return $css;
    }

    public static function personalAccent(mixed $value): ?string
    {
        // The appearance editor supports exactly a six-digit hex accent.
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) ? $value : null;
    }

    private static function isColour(string $value, int $depth = 0): bool
    {
        if ($depth > 4) {
            return false;
        }
        if (preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/D', $value)
            || in_array($value, explode(' ', self::NAMED_COLOURS), true)) {
            return true;
        }
        if (! preg_match('/^([a-z-]+)\((.*)\)$/D', $value, $function)) {
            return false;
        }
        [$all, $name, $args] = $function;
        $args = trim($args);
        if ($name === 'color-mix') {
            return self::isColourMix($args, $depth);
        }

        $number = self::NUMBER;
        $channel = '(?:'.$number.'%?|none)';
        $percent = '(?:'.$number.'%|none)';
        $hue = '(?:'.$number.'(?:deg|grad|rad|turn)?|none)';
        $alpha = '(?:\s*/\s*'.$channel.')?';
        $pattern = match ($name) {
            'rgb', 'rgba' => $channel.' '.$channel.' '.$channel.$alpha,
            'hsl', 'hsla', 'hwb' => $hue.' '.$percent.' '.$percent.$alpha,
            'lab', 'oklab' => $channel.' '.$channel.' '.$channel.$alpha,
            'lch', 'oklch' => $channel.' '.$channel.' '.$hue.$alpha,
            'color' => '(?:srgb|srgb-linear|display-p3|a98-rgb|prophoto-rgb|rec2020|xyz|xyz-d50|xyz-d65) '.$channel.' '.$channel.' '.$channel.$alpha,
            default => null,
        };
        if ($pattern !== null && preg_match('~^'.$pattern.'$~D', $args)) {
            return true;
        }
        // Legacy comma syntax cannot contain "none" or mixed RGB channel units.
        $commaAlpha = '(?:\s*,\s*'.$number.'%?)?';
        foreach ($name === 'rgb' || $name === 'rgba' ? [$number, $number.'%'] : [] as $component) {
            if (preg_match('/^'.$component.'\s*,\s*'.$component.'\s*,\s*'.$component.$commaAlpha.'$/D', $args)) {
                return true;
            }
        }

        return in_array($name, ['hsl', 'hsla'], true)
            && preg_match('/^'.$number.'(?:deg|grad|rad|turn)?\s*,\s*'.$number.'%\s*,\s*'.$number.'%'.$commaAlpha.'$/D', $args) === 1;
    }

    private static function isColourMix(string $args, int $depth): bool
    {
        // Split only the three outer arguments, retaining nested colour functions.
        $parts = [];
        $start = 0;
        $level = 0;
        for ($i = 0, $length = strlen($args); $i < $length; $i++) {
            if ($args[$i] === '(') {
                $level++;
            } elseif ($args[$i] === ')' && --$level < 0) {
                return false;
            } elseif ($args[$i] === ',' && $level === 0) {
                $parts[] = trim(substr($args, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $parts[] = trim(substr($args, $start));
        if ($level !== 0 || count($parts) !== 3
            || ! preg_match('/^in (?:(?:srgb|srgb-linear|display-p3|a98-rgb|prophoto-rgb|rec2020|lab|oklab|xyz|xyz-d50|xyz-d65)|(?:hsl|hwb|lch|oklch)(?: (?:shorter|longer|increasing|decreasing) hue)?)$/D', $parts[0])) {
            return false;
        }
        $weights = [];
        foreach (array_slice($parts, 1) as $part) {
            $weight = null;
            if (preg_match('/^(.*) ('.self::NUMBER.')%$/D', $part, $weighted)) {
                $part = $weighted[1];
                $weight = (float) $weighted[2];
                if ($weight < 0 || $weight > 100) {
                    return false;
                }
            }
            if (! self::isColour($part, $depth + 1)) {
                return false;
            }
            $weights[] = $weight;
        }

        return $weights !== [0.0, 0.0];
    }
}

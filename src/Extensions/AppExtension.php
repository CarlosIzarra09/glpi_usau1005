<?php


namespace Glpi\Twig;

define('GLPI_ROOT', dirname(__DIR__, 2));
include(GLPI_ROOT . "/captcha/captcha.php");

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;


class AppExtension extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('getNewCaptcha', [$this, 'getNewCaptcha']),
        ];
    }

    public function getNewCaptcha()
    {
        $var_captcha = getImageCaptcha();
        return "Saludos :v";
    }
}
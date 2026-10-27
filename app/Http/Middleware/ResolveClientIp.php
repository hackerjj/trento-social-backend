<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
/**
 * Fija la IP real del visitante en un solo lugar, antes de que cualquier
 * controlador o el throttle lean $request->ip().
 *
 * Cadena real en Render (ver diagnostico del 27 oct 2026):
 *   cliente -> edge de Cloudflare (104.x) -> balanceador de Render (10.x)
 *   -> proxy local del contenedor (127.0.0.1) -> Laravel
 * y X-Forwarded-For llega como "[lo que mande el cliente], IP real, 104.x, 10.x".
 *
 * Por que no '*': con trustProxies('*') Symfony solo confia en REMOTE_ADDR
 * (127.0.0.1) y devolvia el 10.x interno de Render. Tampoco se toma el primer
 * elemento de X-Forwarded-For, porque el cliente puede inventarlo.
 *
 * Fuente preferida: CF-Connecting-IP. Cloudflare lo reescribe siempre con la IP
 * de la conexion real y bloquea (403) a quien intenta mandarlo. Solo se usa si
 * el request llega desde un salto confiable (TRUSTED_PROXIES). En ese caso se
 * reemplaza X-Forwarded-For por esa IP, asi Symfony la devuelve en ip() sin
 * perder la confianza en X-Forwarded-Proto/Host.
 *
 * Respaldo: si no hay CF-Connecting-IP valido, trustProxies(TRUSTED_PROXIES) en
 * bootstrap/app.php recorre X-Forwarded-For de derecha a izquierda saltando solo
 * saltos confiables y toma la primera IP no confiable (la del cliente). Lo que el
 * cliente agregue a la izquierda queda ignorado.
 */
class ResolveClientIp
{
    /**
     * Saltos de infraestructura en los que confiamos: loopback (proxy local del
     * contenedor), redes privadas RFC 1918 (balanceador interno de Render) y los
     * rangos publicados de Cloudflare (https://www.cloudflare.com/ips/).
     */
    public const TRUSTED_PROXIES = [
        // Loopback
        '127.0.0.0/8',
        '::1',
        // Redes privadas (Render interno)
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        // Cloudflare IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // Cloudflare IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
    public function handle(Request $request, Closure $next): Response
    {
        $remoteAddr = (string) $request->server('REMOTE_ADDR', '');
        $cfIp = trim((string) $request->header('CF-Connecting-IP', ''));
        if ($remoteAddr !== ''
            && IpUtils::checkIp($remoteAddr, self::TRUSTED_PROXIES)
            && $cfIp !== ''
            && filter_var($cfIp, FILTER_VALIDATE_IP) !== false
        ) {
            $request->headers->set('X-Forwarded-For', $cfIp);
            $request->server->set('HTTP_X_FORWARDED_FOR', $cfIp);
        }
        return $next($request);
    }
}

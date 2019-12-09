<?php
declare(strict_types = 1);

namespace Skautis\Wsdl;

use Skautis\EventDispatcher\EventDispatcherInterface;
use Skautis\Config;
use Skautis\SkautisQuery;
use Skautis\User;

/**
 * Třída pro správu webových služeb SkautISu
 */
class WsdlManager
{

    /**
     * @var WebServiceFactoryInterface
     */
    protected $webServiceFactory;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var array<int, array{eventName: string, callback: (callable(SkautisQuery): void)}>
     */
    protected $webServiceListeners = [];

    /**
     * Pole aktivních webových služeb
     *
     * @var array<string, WebServiceInterface>
     */
    protected $webServices = [];


    /**
     * @param WebServiceFactoryInterface $webServiceFactory továrna pro vytváření objektů webových služeb
     * @param Config $config
     */
    public function __construct(WebServiceFactoryInterface $webServiceFactory, Config $config)
    {
        $this->webServiceFactory = $webServiceFactory;
        $this->config = $config;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Získá objekt webové služby
     *
     * @param string $name celé jméno webové služby
     * @param string|null $loginId skautIS login token
     */
    public function getWebService(string $name, ?string $loginId = null): WebServiceInterface
    {
        $key = $loginId . '_' . $name;

        if (!isset($this->webServices[$key])) {
            $options = $this->config->getSoapOptions();
            $options[User::ID_LOGIN] = $loginId;
            $this->webServices[$key] = $this->createWebService($name, $options);
        }

        return $this->webServices[$key];
    }

    /**
     * Vytváří objekt webové služby
     *
     * @param string $name jméno webové služby
     * @param array<string, mixed> $options volby pro SoapClient
     */
    public function createWebService(string $name, array $options = []): WebServiceInterface
    {
        $webService = $this->webServiceFactory->createWebService($this->getWebServiceUrl($name), $options);

        if ($webService instanceof EventDispatcherInterface) {
            // Zaregistruj listenery na vytvořeném objektu webové služby, pokud je to podporováno
            foreach ($this->webServiceListeners as $listener) {
                $webService->subscribe($listener['eventName'], $listener['callback']);
            }
        }

        return $webService;
    }

    /**
     * Vrací URL webové služby podle jejího jména
     */
    protected function getWebServiceUrl(string $name): string
    {
        if (!WebServiceName::isValidServiceName($name)) {
          throw new WsdlException("Web service '$name' not found.");
        }

        return $this->config->getBaseUrl() . 'JunakWebservice/' . rawurlencode($name) . '.asmx?WSDL';
    }

    public function isMaintenance(): bool
    {
        // Transformuje PHP error/warning do Exception
        // Funkce get_headers totiž používá warning když má problém aby vysvětlil co se děje
        // Pokud například DNS selže tak to hodí PHP warning, který nejde chytat jako exception
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
          throw new MaintenanceErrorException($errstr, $errno, $errfile, $errline);
        });

        try {
          $headers = get_headers($this->getWebServiceUrl('UserManagement'));

          return !$headers || !in_array('HTTP/1.1 200 OK', $headers, true);
        }
        finally {
          restore_error_handler();
        }
    }

    /**
     * Přidá listener na spravovaných vytvářených webových služeb.
     *
     * @param callable(SkautisQuery): void $callback
     */
    public function addWebServiceListener(string $eventName, callable $callback): void
    {
        $this->webServiceListeners[] = [
            'eventName' => $eventName,
            'callback' => $callback,
        ];
    }
}

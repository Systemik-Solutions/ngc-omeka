<?php

namespace App;

use Omeka\Mvc\Application;

class Omeka
{
    private static $app = null;

    private static $authEmail = null;

    private static $authPassword = null;

    private static bool $bootstrapLocked = false;

    /**
     * Prevent the application from being bootstrapped.
     *
     * Used while the distribution code is being updated. PHP cannot replace a class once it is
     * declared, so an instance bootstrapped before the download would keep running the old core and
     * module code for the rest of the process, silently applying the wrong upgrades. Locking makes
     * an accidental bootstrap fail loudly instead.
     */
    public static function lockBootstrap(): void
    {
        self::$bootstrapLocked = true;
    }

    /**
     * Allow the application to be bootstrapped again.
     */
    public static function unlockBootstrap(): void
    {
        self::$bootstrapLocked = false;
    }

    /**
     * Bootstrap Omeka application.
     *
     * @throws \LogicException if bootstrapping is currently locked.
     */
    public static function bootstrap(): void
    {
        if (self::$bootstrapLocked) {
            throw new \LogicException(
                'Omeka S must not be bootstrapped while the distribution code is being updated, because PHP '
                . 'cannot reload classes that are already loaded. Read the installed versions with '
                . 'App\Distribution\Inspector instead, and bootstrap once the download has finished.'
            );
        }
        $publicDir = __DIR__ . '/../public';
        $bootstrapFile = $publicDir . '/bootstrap.php';
        if (file_exists($bootstrapFile)) {
            // bootstrap.php defines OMEKA_PATH and registers the Omeka autoloader unconditionally, so
            // skip it when reloading an application that has already been bootstrapped.
            if (!defined('OMEKA_PATH')) {
                require $bootstrapFile;
            }
            self::$app = Application::init(require $publicDir . '/application/config/application.config.php');
        }
    }

    /**
     * Authenticate a user.
     *
     * @param string|null $email The user email. If null, use the last used email.
     * @param string|null $password The user password. If null, use the last used password.
     */
    public static function authenticate(string $email = null, string $password = null): bool
    {
        if ($email === null) {
            $email = self::$authEmail;
        } else {
            self::$authEmail = $email;
        }
        if ($password === null) {
            $password = self::$authPassword;
        } else {
            self::$authPassword = $password;
        }

        $app = self::getApp();
        $serviceManager = $app->getServiceManager();
        /**
         * @var \Laminas\Authentication\AuthenticationService $auth
         */
        $auth = $serviceManager->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity($email);
        $adapter->setCredential($password);
        $result = $auth->authenticate();
        return $result->isValid();
    }

    /**
     * Get the Omeka application instance.
     *
     * @return \Laminas\Mvc\Application
     */
    public static function getApp(): \Laminas\Mvc\Application
    {
        if (self::$app === null) {
            self::bootstrap();
        }
        return self::$app;
    }

    /**
     * Reload the Omeka application instance.
     *
     * @return \Laminas\Mvc\Application
     */
    public static function reloadApp(): \Laminas\Mvc\Application
    {
        self::$app = null;
        return self::getApp();
    }
}

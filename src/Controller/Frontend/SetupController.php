<?php
namespace App\Controller\Frontend;

use App\Acl;
use App\Auth;
use App\Form\StationForm;
use App\Radio\Adapters;
use App\Radio\Configuration;
use App\Radio\Frontend\SHOUTcast;
use Doctrine\ORM\EntityManager;
use App\Entity;
use App\Http\Request;
use App\Http\Response;
use Psr\Http\Message\ResponseInterface;

class SetupController
{
    /** @var EntityManager */
    protected $em;

    /** @var Auth */
    protected $auth;

    /** @var Acl */
    protected $acl;

    /** @var StationForm */
    protected $station_form;

    /** @var array */
    protected $settings_form_config;

    /**
     * @param EntityManager $em
     * @param Auth $auth
     * @param Acl $acl
     * @param StationForm $station_form
     * @param array $settings_form_config
     *
     * @see \App\Provider\FrontendProvider
     */
    public function __construct(
        EntityManager $em,
        Auth $auth,
        Acl $acl,
        StationForm $station_form,
        array $settings_form_config
    )
    {
        $this->em = $em;
        $this->auth = $auth;
        $this->acl = $acl;
        $this->station_form = $station_form;
        $this->settings_form_config = $settings_form_config;
    }

    /**
     * Setup Routing Controls
     *
     * @param Request $request
     * @param Response $response
     * @return ResponseInterface
     */
    public function indexAction(Request $request, Response $response): ResponseInterface
    {
        $current_step = $this->_getSetupStep();
        return $response->withRedirect($request->getRouter()->named('setup:'.$current_step));
    }

    /**
     * Placeholder function for "setup complete" redirection.
     *
     * @param Request $request
     * @param Response $response
     * @return ResponseInterface
     */
    public function completeAction(Request $request, Response $response): ResponseInterface
    {
        $request->getSession()->flash('<b>' . __('Setup has already been completed!') . '</b>', 'red');

        return $response->withRedirect($request->getRouter()->named('dashboard'));
    }

    /**
     * Setup Step 1:
     * Create Super Administrator Account
     *
     * @param Request $request
     * @param Response $response
     * @return ResponseInterface
     */
    public function registerAction(Request $request, Response $response): ResponseInterface
    {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'register') {
            return $response->withRedirect($request->getRouter()->named('setup:'.$current_step));
        }

        // Create first account form.
        if (!empty($_POST['username']) && !empty($_POST['password'])) {
            $data = $_POST;

            // Create actions and roles supporting Super Admninistrator.
            $role = new Entity\Role;
            $role->setName(__('Super Administrator'));

            $this->em->persist($role);
            $this->em->flush();

            $rha = new Entity\RolePermission($role);
            $rha->setActionName('administer all');

            $this->em->persist($rha);

            // Create user account.
            $user = new Entity\User;
            $user->setEmail($data['username']);
            $user->setAuthPassword($data['password']);
            $user->getRoles()->add($role);
            $this->em->persist($user);

            // Write to DB.
            $this->em->flush();

            // Log in the newly created user.
            $this->auth->authenticate($data['username'], $data['password']);
            $this->acl->reload();

            return $response->withRedirect($request->getRouter()->named('setup:index'));
        }

        return $request->getView()
            ->renderToResponse($response, 'frontend/setup/register');
    }

    /**
     * Setup Step 2:
     * Create Station and Parse Metadata
     *
     * @param Request $request
     * @param Response $response
     * @return ResponseInterface
     */
    public function stationAction(Request $request, Response $response): ResponseInterface
    {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'station') {
            return $response->withRedirect($request->getRouter()->named('setup:'.$current_step));
        }

        if (false !== $this->station_form->process($request)) {
            return $response->withRedirect($request->getRouter()->named('setup:settings'));
        }

        return $request->getView()->renderToResponse($response, 'frontend/setup/station', [
            'form' => $this->station_form,
        ]);
    }

    /**
     * Setup Step 3:
     * Set site settings.
     *
     * @param Request $request
     * @param Response $response
     * @return ResponseInterface
     */
    public function settingsAction(Request $request, Response $response): ResponseInterface
    {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'settings') {
            return $response->withRedirect($request->getRouter()->named('setup:'.$current_step));
        }

        $form = new \AzuraForms\Form($this->settings_form_config);

        /** @var Entity\Repository\SettingsRepository $settings_repo */
        $settings_repo = $this->em->getRepository(Entity\Settings::class);

        $existing_settings = $settings_repo->fetchArray(false);
        $form->populate($existing_settings);

        if ($request->getMethod() === 'POST' && $form->isValid($_POST)) {
            $data = $form->getValues();

            // Mark setup as complete along with other settings changes.
            $data['setup_complete'] = time();

            $settings_repo->setSettings($data);

            // Notify the user and redirect to homepage.
            $request->getSession()->flash('<b>' . __('Setup is now complete!') . '</b><br>' . __('Continue setting up your station in the main AzuraCast app.'),
                'green');

            return $response->withRedirect($request->getRouter()->named('dashboard'));
        }

        return $request->getView()->renderToResponse($response, 'frontend/setup/settings', [
            'form' => $form,
        ]);
    }

    /**
     * Determine which step of setup is currently active.
     *
     * @return string
     * @throws \App\Exception\NotLoggedIn
     */
    protected function _getSetupStep(): string
    {
        /** @var Entity\Repository\SettingsRepository $settings_repo */
        $settings_repo = $this->em->getRepository(Entity\Settings::class);

        if (0 !== (int)$settings_repo->getSetting(Entity\Settings::SETUP_COMPLETE, 0)) {
            return 'complete';
        }

        // Step 1: Register
        $num_users = (int)$this->em->createQuery(/** @lang DQL */'SELECT COUNT(u.id) FROM App\Entity\User u')->getSingleScalarResult();
        if (0 === $num_users) {
            return 'register';
        }

        // If past "register" step, require login.
        if (!$this->auth->isLoggedIn()) {
            throw new \App\Exception\NotLoggedIn;
        }

        // Step 2: Set up Station
        $num_stations = (int)$this->em->createQuery(/** @lang DQL */'SELECT COUNT(s.id) FROM App\Entity\Station s')->getSingleScalarResult();
        if (0 === $num_stations) {
            return 'station';
        }

        // Step 3: System Settings
        return 'settings';
    }
}

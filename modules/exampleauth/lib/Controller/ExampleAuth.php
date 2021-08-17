<?php

declare(strict_types=1);

namespace SimpleSAML\Module\exampleauth\Controller;

use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Module\exampleauth\Auth\Source\External;
use SimpleSAML\Session;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session as SymfonySession;

use function array_key_exists;
use function preg_match;
use function session_id;
use function session_start;
use function urldecode;

/**
 * Controller class for the exampleauth module.
 *
 * This class serves the different views available in the module.
 *
 * @package simplesamlphp/simplesamlphp
 */
class ExampleAuth
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Session */
    protected Session $session;


    /**
     * Controller constructor.
     *
     * It initializes the global configuration and session for the controllers implemented here.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use by the controllers.
     * @param \SimpleSAML\Session $session The session to use by the controllers.
     *
     * @throws \Exception
     */
    public function __construct(
        Configuration $config,
        Session $session
    ) {
        $this->config = $config;
        $this->session = $session;
    }


    /**
     * Auth testpage.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function authpage(Request $request): Template
    {
        /**
         * This page serves as a dummy login page.
         *
         * Note that we don't actually validate the user in this example. This page
         * just serves to make the example work out of the box.
         */
        if (!$request->query->has('ReturnTo')) {
            die('Missing ReturnTo parameter.');
        }

        $httpUtils = new Utils\HTTP();
        $returnTo = $httpUtils->checkURLAllowed($request->get('ReturnTo'));

        /**
         * The following piece of code would never be found in a real authentication page. Its
         * purpose in this example is to make this example safer in the case where the
         * administrator of the IdP leaves the exampleauth-module enabled in a production
         * environment.
         *
         * What we do here is to extract the $state-array identifier, and check that it belongs to
         * the exampleauth:External process.
         */
        if (!preg_match('@State=(.*)@', $returnTo, $matches)) {
            die('Invalid ReturnTo URL for this example.');
        }

        /**
         * The loadState-function will not return if the second parameter does not
         * match the parameter passed to saveState, so by now we know that we arrived here
         * through the exampleauth:External authentication page.
         */
        Auth\State::loadState(urldecode($matches[1]), 'exampleauth:External');

        // our list of users.
        $users = [
            'student' => [
                'password' => 'student',
                'uid' => 'student',
                'name' => 'Student Name',
                'mail' => 'somestudent@example.org',
                'type' => 'student',
            ],
            'admin' => [
                'password' => 'admin',
                'uid' => 'admin',
                'name' => 'Admin Name',
                'mail' => 'someadmin@example.org',
                'type' => 'employee',
            ],
        ];

        // time to handle login responses; since this is a dummy example, we accept any data
        $badUserPass = false;
        if ($request->getMethod() === 'POST') {
            $username = $request->request->get('username');
            $password = $request->request->get('password');

            if (!isset($users[$username]) || $users[$username]['password'] !== $password) {
                $badUserPass = true;
            } else {
                $user = $users[$username];

                $session = new SymfonySession();
                if (!$session->getId()) {
                    $session->start();
                }

                $session->set('uid', $user['uid']);
                $session->set('name', $user['name']);
                $session->set('mail', $user['mail']);
                $session->set('type', $user['type']);

                $httpUtils->redirectTrustedURL($returnTo);
            }
        }

        // if we get this far, we need to show the login page to the user
        $t = new Template($this->config, 'exampleauth:authenticate.twig');
        $t->data['badUserPass'] = $badUserPass;
        $t->data['returnTo'] = $returnTo;
        $t->send();
    }


    /**
     * Redirect testpage.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     */
    public function redirect(Request $request): RunnableResponse
    {
        /**
         * Request handler for redirect filter test.
         */
        if (!$request->has('StateId')) {
            throw new Error\BadRequest('Missing required StateId query parameter.');
        }

        /** @var array $state */
        $state = Auth\State::loadState($request->get('StateId'), 'exampleauth:redirectfilter-test');
        $state['Attributes']['RedirectTest2'] = ['OK'];

        return new RunnableResponse([Auth\ProcessingChain::class, 'resumeProcessing'], [$state]);
    }


    /**
     * Resume testpage.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     */
    public function resume(Request $request): RunnableResponse
    {
        /**
         * This page serves as the point where the user's authentication
         * process is resumed after the login page.
         *
         * It simply passes control back to the class.
         */

        return new RunnableResponse([External::class, 'resume'], []);
    }
}

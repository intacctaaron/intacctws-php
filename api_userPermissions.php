<?php
/**
 * file api_userPermissions.php
 *
 * @author    Aaron Harris <aharris@intacct.com>
 * @copyright 2014 Intacct Corporation
 *
 * Copyright (c) 2014, Intacct OpenSource Initiative
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following
 * disclaimer in the documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
 * STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN
 * IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * OVERVIEW
 * The general pattern for using this SDK is to first create an instance of api_session and call either
 * connectCredentials or connectSessionId to start an active session with the Intacct Web Services gateway.
 * You will then pass the api_session as an argument in the api_post class methods.  intacctws-php handles all
 * XML serialization and de-serialization and HTTPS transport.
 */

/**
 * Class api_userPermissions
 *
 * stateful object containing the set of applications, policies, and rights assigned to a user
 */
class api_userPermissions
{

    private $userPermissions;

    /**
     * Construct an instance of the api_userPermissions object
     *
     * @param simpleXmlElement $element the <data> portion of an Intacct XML response
     */
    public function __construct(simpleXmlElement $element)
    {

        $permObjects = array();

        foreach ($element->permissions->appSubscription as $appSubscription) {
            $permObjects[] = new api_userPermission($appSubscription);
        }

        // turn the XML response into a usable array of objects
        $this->userPermissions = $permObjects;
    }

    /**
     * Get the array of userPermission objects
     *
     * @return array
     */
    public function getUserPermissions()
    {
        return $this->userPermissions;
    }
}

/**
 * Class api_userPermission is the set of policies and rights for a specific application
 */
class api_userPermission
{

    private $applicationName;
    private $policies;

    /**
     * Create an instance of the api_userPermission object
     *
     * @param simpleXmlElement $element an <application> element returned by getUserPermissions
     */
    public function __construct(simpleXmlElement $element)
    {
        $this->applicationName = (string)$element->applicationName;
        $this->policies = array();
        foreach ($element->policies->policy as $policy) {
            $this->policies[] = new api_policyPermission($policy);
        }
    }
}

/**
 * Class api_policyPermission
 *
 * Set of rights for a specific policy
 */
class api_policyPermission
{

    private $policyName;
    private $rights;

    /**
     * Construct an instance of the policyPermission object
     *
     * @param simpleXmlElement $element the <policy> element returned by getUserPermissions
     */
    function __construct(simpleXmlElement $element)
    {
        $this->policyName = (string)$element->policyName;
        $this->rights = explode("|", (string)$element->rights);
    }

    /**
     * The Policy Name
     *
     * @return mixed
     */
    public function getPolicyName()
    {
        return $this->policyName;
    }

    /**
     * The list of rights allowed for this policy
     *
     * @return mixed
     */
    public function getRights()
    {
        return $this->rights;
    }

}
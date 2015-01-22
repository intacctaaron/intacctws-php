<?php
/**
 * Copyright (c) 2013, Intacct OpenSource Initiative
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
 * Class api_ddsFileType Stateful object for defining file format information used in DDS deliveries
 */
class api_ddsFileConfiguration {

    const DDS_FILETYPE_UNIX = 'unix';
    const DDS_FILETYPE_MAC = 'mac';
    const DDS_FILETYPE_WINDOWS = 'windows';

    /**
     * @var string
     */
    private $delimiter;

    /**
     * @var string
     */
    private $enclosure;

    /**
     * @var bool
     */
    private $includeHeaders;

    /**
     * @var string
     */
    private $fileFormat;

    /**
     * Construct an instance of the ddsFileConfiguration object
     *
     * @param string $delimiter      Character for separating values in a row
     * @param string $enclosure      Character(s) for enclosing non-numeric values
     * @param bool   $includeHeaders Whether or not to include a starting row with column names
     * @param string $fileFormat     One of DDS_FILETYPE* constants.  Specify the OS for which to encode the file.
     *                               Will affect row separators.
     */
    public function __construct(
        $delimiter = ',', $enclosure = '"', $includeHeaders = false, $fileFormat = self::DDS_FILETYPE_UNIX
    ) {

        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->includeHeaders = $includeHeaders;
        $this->fileFormat = $fileFormat;

    }

    /**
     * Return an XML string suitable for the Intacct API
     *
     * @return mixed
     */
    public function toApiXml()
    {
        $xmlObj = new simpleXmlElement("<fileConfiguration/>");
        $xmlObj->addChild("delimiter", $this->getDelimiter());
        $xmlObj->addChild("enclosure", $this->getEnclosure());
        $xmlObj->addChild("includeHeaders", $this->getIncludeHeaders());
        $xmlObj->addChild("fileFormat", $this->getFileFormat());

        $xmlStr = trim(substr($xmlObj->asXML(), strpos($xmlObj->asXML(), "\n")));
        return $xmlStr;
    }

    /**
     * Get the character for delimiting values
     *
     * @return mixed
     */
    public function getDelimiter()
    {
        return $this->delimiter;
    }

    /**
     * Get the character(s) for enclosing non-numeric values
     *
     * @return mixed
     */
    public function getEnclosure()
    {
        return $this->enclosure;
    }

    /**
     * Get the file format value
     *
     * @return mixed
     */
    public function getFileFormat()
    {
        return $this->fileFormat;
    }

    /**
     * Get the includeHeaders value
     * 
     * @return mixed
     */
    public function getIncludeHeaders()
    {
        return $this->includeHeaders;
    }


} 
<?php
/**
 * Copyright (c) 2013 Man4x
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @project     Magento Man4x Mondial Relay Module
 * @description Observer for
 *                  - <sales_convert_quote_address_to_order> (frontend)
 *                  - <sales_order_shipment_save_before> (adminhtml)  
 * @author      Emmanuel Catrysse (ecatrysse@claudebell.com)
 * @license     http://www.opensource.org/licenses/MIT  The MIT License (MIT)
 */

class Man4x_MondialRelay_Model_Observer
{
   
    /**
     * Observer for <sales_convert_quote_address_to_order> frontend event
     * Replace customer shipping address with the selected pickup address
     */
    public function replaceShippingAddress($observer)
    {
        $_order = $observer->getEvent()->getOrder();
        $_quote = $_order->getQuote();
        $_carrier = $_order->getShippingCarrier();
        if ($_carrier instanceof Man4x_MondialRelay_Model_Carrier_Pickup)
        {
            $_mrPickups = Mage::getModel('checkout/session')->getData('mr_pickups');
            $_address = $_quote->getShippingAddress();
            $_method = explode('_', $_address->getShippingMethod());
            
            if (2 == count($_method) && is_array($_selpickup = current($_mrPickups)))
            {
                // Pick-up selected on map (mondialrelaypickup_24R): there is only one pick-up saved in checkout/session
                $_method[] = $_selpickup['id'];
               
            }
            
            if (isset($_mrPickups[$_method[2]]))
            {
                $_selpickup = $_mrPickups[$_method[2]];
                
                $_pickupMethod = implode('_', $_method);
                $_order->setShippingMethod($_pickupMethod);
                
                // Shipping address replacement
                $_address   ->setCompany($_selpickup['name'])
                            ->setStreet($_selpickup['street'])
                            ->setPostcode($_selpickup['postcode'])
                            ->setCity($_selpickup['city'])
                            ->setCountryId($_selpickup['country_id'])
                            ->setShippingMethod($_pickupMethod);

                $_quote->setShippingAddress($_address); 
            }
        }
    }

    /**
     * Observer for <sales_order_shipment_save_before> adminhtml event
     * Register Mondial Relay shipping code for the shipment
     */
    public function registerShipment($observer)
    {
        // Looking for web service registration flag (set in Man4x_MondialRelay_Sales_ShippingController->massShippingWsAction)     
        if (! Mage::getSingleton('adminhtml/session')->hasMondialRelayWsRegistration())
        {
            return;
        }
        
        $_shipment = $observer->getShipment();
        $_order = $_shipment->getOrder();
        $_carrier = $_order->getShippingCarrier();

        // Check if order is relevant for Mondial Relay shipment
        if ($_order->getId()
            // MondialRelay is the registered shipping method for the given order
            && ($_carrier instanceof Man4x_MondialRelay_Model_Carrier_Abstract))
        {
            // We record the shipment at Mondial Relay web service
            $_wsResult = $_carrier->wsRegisterShipment($_order);
            if (! property_exists($_wsResult, 'ExpeditionNum'))
            {
                Mage::throwException(
                    Mage::helper('mondialrelay')->__(
                            'Mondial Relay shipment error for order #%s (%s)',
                            $_order->getIncrementId(),
                            Mage::helper('mondialrelay')->convertStatToTxt($_wsResult->STAT)
                            )
                    );
            }
            
            // We create the shipment track
            $_track = Mage::getModel('sales/order_shipment_track')
                            ->setNumber($_wsResult->ExpeditionNum)
                            ->setCarrier('Mondial Relay')
                            ->setCarrierCode($_carrier->getCarrierCode())
                            ->setTitle('Mondial Relay')
                            ->setPopup(1);
            
            $_shipment->addTrack($_track);
        }
    }   
}
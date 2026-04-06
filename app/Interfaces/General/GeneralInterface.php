<?php

namespace App\Interfaces\General;
use App\Partner;

interface GeneralInterface
{
    public function getPartnerList($search = null,$perPage);
    public function createPartner(array $data);
    public function getPartnerById(int $id): Partner;
    public function updatePartner(int $id, array $data);
    public function updateExpiryReminder(int $id, array $expireData);

    public function getThirdPartyCompanyList(array $search,int $perPage);
    public function createThirdPartyCompany(array $data);
    public function getThirdPartyCompanyById(int $id);
    public function updateThirdPartyCompany(int $id, array $data);

    public function getCaptainWalletSummary(int $captainId);
    public function getCaptainSafeCustodyAmountSummary(int $captainId); 
    public function getTransactionAmountSummary(int $captainId);
    public function getAccountReceivable(int $captainId);
    public function createCaptainSafeCustodyAmount(array $data);
    public function createCaptainReceivableAmount(array $data);

    public function getCompanies(array $data,$perPage);
    public function getCompanyDetails(int $id);
    public function updateCompany(int $id, array $data);           
    
}

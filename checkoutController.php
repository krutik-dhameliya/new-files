<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use DB;
use Auth;
use Mail;
use App\models\Home;
use App\models\Reports;
use App\models\CmsFooter;
use App\models\CPReports;
use App\models\Checkout;
use App\models\Leads;
use Validator;
use Cart;
use PDF;
use Aws\S3\S3Client;
use DateTime;
use DateTimeZone;
use Session;
use Srmklive\PayPal\Services\ExpressCheckout;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use PayPal;
class CheckoutController extends Controller
{
    protected  $CmsFooterModel =''; 
    protected  $HomeModel ='';
	protected  $ReportsModel ='';	
	protected  $CPReportsModel ='';	
	protected  $CheckoutModel ='';
	protected  $curlURL ='';	
	protected  $paymentReceivedURL ='';
	protected  $replaceRYearsForPublished =array();
	protected  $replaceRYearsForUpcoming=array();
	protected  $replaceRYearsForPublishedWith ='';
	protected  $replaceRYearsForUpcomingWith ='';
	protected  $replaceCPYearsForPublished =array();
	protected  $replaceCPYearsForUpcoming=array();
	protected  $replaceCPYearsForPublishedWith ='';
	protected  $replaceCPYearsForUpcomingWith ='';
    function __construct() {
        $this->HomeModel = new Home;
		$this->ReportsModel = new Reports;
		$this->CmsFooterModel = new CmsFooter;
		$this->CPReportsModel = new CPReports;
		$this->CheckoutModel = new Checkout;
		/* 6/1/2020 */
		$this->LeadsModel = new Leads;
		/* 6/1/2020 */
		$this->curlURL="http://10.10.1.43/sales_efforts/public/api/wireTransfer";
		$this->paymentReceivedURL='http://10.10.1.43/sales_efforts/public/api/paymentReceived';
		$this->replaceRYearsForPublishedWith='';
		$startYear=date('Y');
		$endYear=date('Y')+7;
		$upcomingYer=(string)($startYear."-".$endYear);
		$this->replaceRYearsForUpcomingWith=$upcomingYer;//'2018-2025';
		$this->replaceRYearsForPublished=array();
		$this->replaceRYearsForUpcoming=array("2014 - 2022","2016-2025","2017-2025","2014-2022","2017-2025","2017-2023","2018-2024","2016-2023","2017 - 2023","2015-2023","2015 - 2023");	
		$this->replaceCPYearsForPublishedWith='';
		$this->replaceCPYearsForUpcomingWith='';
		$this->replaceCPYearsForPublished=array();
		$this->replaceCPYearsForUpcoming=array();		
    }
    public function checkCheckoutId($checkoutId)
    {
        $checkCheckoutIdDupticateOrNot = DB::table('amr_checkout')->select('id')->where('id', '=', $checkoutId)->count();
        return $checkCheckoutIdDupticateOrNot;
    }
    public function proceedToCheckout(Request $request)
    {
		$input = $request->all();
        $Status = 'Failed';
		$Message = 'Failed';
		$ResultURL =array();
        $latestCheckoutId = 0;		
        if(count(Cart::content())) {
            $checkoutId = str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
            $checkCheckoutIdDupticateOrNot = $this->checkCheckoutId($checkoutId);
            if($checkCheckoutIdDupticateOrNot == 0) {
				foreach(Cart::content()->reverse() as $items) {					
					$options=array(
						"reportCode" => $items->options->reportCode,
						"pu_status" => $items->options->pu_status,
						"discountPercentage" => $items->options->discountPercentage,
						"originalPrice" => $items->options->originalPrice,
						"license" => $items->options->license,
						"rep_url" => $items->options->url,
						"type" => $items->options->type,
						"deliveryFormat" => $items->options->deliveryFormat,
						"chapter_names" => $items->options->chapter_names,
						"message" => $items->options->message,
						"applicableLicenceTypes" => $items->options->applicableLicenceTypes,
					);					
					$cartItem[$items->rowId]=array(
						"rowId"=>$items->rowId,
						"id"=>$items->id,
						"name"=>$items->name,
						"price"=>$items->price,
						"qty"=>$items->qty,
						"dataPrice"=>'',
						"updatedReportPrice"=>'',
						"options"=>$options);		
				}
				if(!empty($cartItem)) {
					$whereCondition = array(
					'id'=> $checkoutId,
					'datetime'=> Date('Y-m-d H:i:s'),
					'cart_contents'=> serialize($cartItem)
					);
					$latestCheckoutId=DB::table('amr_checkout')->insertGetId($whereCondition);
					$Status = 'Success';
					$Message = 'Checkout Link Generated Successfully.';
					$ResultURL="checkout-final/".md5($checkoutId);
					if(@$input['checkoutType']!=md5('Direct')) { return redirect($ResultURL); }					
				}
            }
            else {
                $this->proceedToCheckout();
            }
        }
		$return =array("Status" => $Status,"Message" => $Message,"ResultURL" => $ResultURL);
		echo json_encode($return);
    } 
	public function checkout($checkoutId,Request $request)
	{
		$Status="Failed";
		if($checkoutId!='')
		{
			$checkoutDetails=$this->CheckoutModel->checkCheckoutExists($checkoutId,false);
			if(!empty($checkoutDetails)) {
				return redirect('checkout-final/'.md5($checkoutId));
				$Status="Success";
				exit;
			}
		}
		return redirect('shopping-cart');		
	}
	public function checkoutfinal($checkoutId,Request $request) {
		$Status="Failed";
		if($checkoutId!='') {
			$checkoutDetails=$this->CheckoutModel->checkCheckoutExists($checkoutId,true);
			if(!empty($checkoutDetails)) {
				
				$actualCheckoutId=$checkoutDetails->id;				
				$queryParameters=$request->getRequestUri();		
				
				$utm_campaigne='';
				if ($request->has('utm_campaign')) {
					$utm_campaigne = trim($request->input('utm_campaign'));
				}		
				
				$removeDeleteButton='No';
				if($utm_campaigne!='' && $checkoutDetails->isCampaignURL=='No')
				{
					$actQ=explode('?',$queryParameters);					
					$allParameters=@$actQ[1];
					$updateCampaign=array("campaignCode"=>$utm_campaigne,"isCampaignURL"=>'Yes',"queryParameters"=>$allParameters,"campaignLinkHitDate"=>date('Y-m-d H:i:s'));
					DB::table('amr_checkout')->where('id', $actualCheckoutId)->update($updateCampaign);
					$removeDeleteButton='Yes';					
				}
				elseif($checkoutDetails->isCampaignURL=='Yes'){
					$removeDeleteButton='Yes';
				}
				$pageData['metaTitle']="Checkout - Allied Market Research";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";   
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";      
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['checkoutDetails'] = $checkoutDetails;
				$pageData['removeDeleteButton'] = $removeDeleteButton;
				$cartContent=unserialize($checkoutDetails->cart_contents);
				$userDetails='';
				if($checkoutDetails->formdata!='' && $checkoutDetails->formdata!=null && $checkoutDetails->formdata!=NULL) {
					$userDetails=unserialize($checkoutDetails->formdata);
				}				
				$pageData['cartContent'] = $cartContent;
				$pageData['userDetails'] = $userDetails;
				$pageData['checkoutType'] = $checkoutDetails->type;

				/* code by krutik for new avenue page */
				$pageData['paymentThrough'] = $checkoutDetails->paymentThrough;
				$paymentThroughAvenue = explode(' ', $checkoutDetails->paymentThrough);
				$pageData['paymentThroughAvenue'] = $paymentThroughAvenue[0];
				$avenueCon = DB::connection('mysqlAVE');
				$pageData['packageDetail'] = $avenueCon->table('packages')->where('id',@$paymentThroughAvenue[1])->select('user_min_limit','user_max_limit','analyst_meeting_credit','on_demand_report_credit','pdf_downloads_credit','data_pack_downloads_credit','company_profiles_downloads_credit','expiry_protection_period')->first();
				if($checkoutDetails->paymentThrough == 'Avenue 1 Discount')
				{
					$code = DB::table('coupons_master')->select('couponCode')->where('couponId', 6)->first();
					$pageData['code'] = $code->couponCode;
				}
				/* code by krutik for new avenue page ends here */

				$pageData['checkoutId'] = $checkoutId;
				$pageData['manualInternetHandlingCharge'] =$checkoutDetails->internetHandlingCharge;
				return view('pages.checkout',$pageData);
			}
			else{
				return redirect('shopping-cart');
			}
		}		
	}
	public function applyCoupon(Request $request) {
		$input = $request->all();
        $Status = 'Failed';
		$Message = 'Failed';
		$ResultURL =array();
		$CouponCode=$input['coupon'];
		$checkoutId=$input['checkout'];
		$orderTotal=0;
		$discountedAmount=0;
		$displayMessage="";
		if(trim($CouponCode)!="")
		{
			$isvalidCoupon=$this->CheckoutModel->isvalidCoupon($CouponCode);
			if(!empty($isvalidCoupon)) {
				$checkoutDetails=$this->CheckoutModel->checkCheckoutExists($checkoutId,true);
				$Status = 'Success';
				$performCheck="Yes";
				if($input['checkoutType']=='manual') {					
					$Message = 'Manual';
					$performCheck="No";					
				}
				if(!empty($checkoutDetails)) {
					$cartContent=unserialize($checkoutDetails->cart_contents);
					$paymentThrough = explode(' ', @$checkoutDetails->paymentThrough);
					$packageId = @$paymentThrough[1];
					/* new krutik */
					if($isvalidCoupon->couponCode == 'FLAT20' && $packageId != '1'){
						$Message = 'Sorry! This Coupon Code.('.$CouponCode.') is not applicable for this purchase.';
					}else{
						if(count($cartContent)>0) {
							// if(count($cartContent)==1 && $cartContent[0]['name'])
							

							foreach($cartContent as $items) {
								$orderTotal=$orderTotal+$items['price'];
							}
						}
						if($orderTotal>0) {						
							if($orderTotal>=$isvalidCoupon->minimumPurchase) 
							{
								if($isvalidCoupon->couponType=='Percentage') {							
									$discountedAmount=round(($orderTotal*$isvalidCoupon->couponDiscount)/100);	
									$displayMessage.="(".$isvalidCoupon->couponDiscount."%)";								
								}
								else if($isvalidCoupon->couponType=='Flat') {
									$discountedAmount=$isvalidCoupon->couponDiscount;
									$displayMessage.="(Flat $".$isvalidCoupon->couponDiscount.')';
								}
								if($discountedAmount>0) {
									$Message = 'Coupon applied successfully.';
								}
							}						
							else
							{
								$Message = 'Purchase of minimun '.$isvalidCoupon->minimumPurchase." is required to avail the offer." ;
							}						
						}
					}
					/* code by krutik for new avenue page ends here */
				}
			}
			else{				
				$Message = 'Sorry! Invalid Coupon Code.('.$CouponCode.')';
			}			
		}
		$return =array(
		"Status" => $Status,
		"Message" => $Message,
		"CouponCode" => $CouponCode,
		"discountedAmount" => $discountedAmount,
		"orderTotal" => $orderTotal,
		"displayMessage" => $displayMessage
		);
		echo json_encode($return);
	}	
	function processCheckoutConfirm(Request $request)
	{
		$input = $request->all();
		$messages = [
			'_token.required' => 'Invalid parameters submitted, please refresh the page',
			'full_name.required' => 'Please provide your full name',
			'company.required' => 'Please provide your company name',
			'jobrole.required' => 'Please provide your job role',
			'email_id.required' => 'Please provide your email id',
			'country.required' => 'Please provide your country',
			'state.required' => 'Please provide your state',
			'zip_code.required' => 'Please provide zip code',
			'contact_no.required' => 'Please provide contact no.',	
			'city.required' => 'Please provide your city',
			'address.required' => 'Please provide your address',
			'captcha_code.required' => 'Security code is required.',
			'checkoutId.required' => 'Invalid parameters submitted, please refresh the page',
		];	
		$validator = Validator::make($input,
		['_token' => 'required','full_name' => 'required','company' => 'required','jobrole' => 'required','email_id' => 'required','country' => 'required','state' => 'required','city' => 'required','zip_code' => 'required','contact_no' => 'required','captcha_code' => 'required','address' => 'required','checkoutId' => 'required'],
		$messages);
		if($validator->fails()){ 
			return redirect($input['return_url'])->withErrors($validator)->withInput();
		}
		else
		{			
			$Message='';
			$name				= preg_replace('/\s+/', ' ', $input['full_name']);
			$email				= preg_replace('/\s+/', ' ', $input['email_id']);
			$company			= preg_replace('/\s+/', ' ', $input['company']);
			$contact_no			= preg_replace('/\s+/', ' ', $input['contact_no']);
			$address			= preg_replace('/\s+/', ' ', $input['address']);
			$state				= preg_replace('/\s+/', ' ', $input['state']);
			$city				= preg_replace('/\s+/', ' ', $input['city']);
			$country 			= preg_replace('/\s+/', ' ', $input['country']);
			$zip_code			= preg_replace('/\s+/', ' ', $input['zip_code']);
			$CouponCode			= preg_replace('/\s+/', ' ', $input['CouponCode']);
			$data_pack_discount = preg_replace('/\s+/', ' ', @$input['data_pack']);	
			$gstno				= preg_replace('/\s+/', ' ', @$input['gstno']);	
			$jobrole			= preg_replace('/\s+/', ' ', $input['jobrole']);
			if(isset($gstno) && !empty($gstno)){ $gstno = $gstno; }
			else { $gstno=NULL; }
			$form_for 			= "Checkout/Paypal";
			$countr_info = (explode('-',$country)) ? explode('-',$country): array('0',$country);
			$country = $countr_info[1];
			$countrycode = $countr_info[0];
			$form_data = array(
			'Name'=>$name,
			'Email'=> $email,
			'Company'=>$company,
			'Phone'=>$contact_no,
			'Address'=>$address,
			'State'=>$state,
			'City'=>$city,
			'Country'=>$country,
			'discount_note'=>$data_pack_discount,
			'Zip'=>$zip_code);
			$checkoutId = preg_replace('/\s+/', ' ', $input['checkoutId']);
			$checkCheckoutExists=$this->CheckoutModel->checkCheckoutExists($checkoutId,true);
			if(!empty($checkCheckoutExists)) {
				$checkoutLink		= $checkCheckoutExists->type;
				$cart_id			= $checkCheckoutExists->id;	
				
				if($checkCheckoutExists->isCampaignURL!='Yes') {
					$serializearray=array(
					"Name" => $name,
					"Company" => $company,	
					"Designation" => $jobrole,
					"Email" => $email,
					"Country" => $country,
					"country_code" => $countrycode,
					"Phone" => $contact_no,
					"Address" => $address,
					"State" => $state,
					"City" => $city,
					"Zip" => $zip_code,
					"Chk_Status" => '1',
					"Password" => '');
					$updateData=array('formdata'=>serialize($serializearray));
					DB::table('amr_checkout')->where('id', intval($checkCheckoutExists->id))->update($updateData);
				}
				
				
				$manualInternetHandlingCharge = $checkCheckoutExists->internetHandlingCharge;	
				$internetHandling=0;
				$grand_total=0;
				$subTotal=0;
				$total_discounted_price = 0;
				$actualDatapackPrice=0;
				$actualNewVersionPrice=0;
				$couponDiscountPrice=0;
				$couponDiscountPerORFlat='';
				$cartTotal=0;
				$cart_contents=unserialize($checkCheckoutExists->cart_contents);
				if(count($cart_contents)>0) {
					$rowCounter=0;
					foreach($cart_contents as $items) {	
						$rowCounter++;
						$rowId=@$items['rowId'];
						if($rowId=='')
						{
							$rowId=@$items['rowid'];
						}
						$reportId=intval($items['id']);
						$licenceType=$items['options']['license'];
						$reportPrice=$items['price'];
						$actualReportLicencePrice=@$items['options']['originalPrice'];
						$reportDiscount=@$items['options']['discountPercentage'];
						$getPrices=$this->ReportsModel->getPricesAndReportAge($reportId);
						$reportAge=intval($getPrices['MonthsOld']);	
						$upcomingPublishedStatus=$items['options']['pu_status'];
						$cartTotal = $cartTotal + $reportPrice;	
						$subTotal = $subTotal + $reportPrice;
						$data_pack_discounted_price=0;
						$newVersionDiscountedPrice=0;
						$discounted_price_new_version=0;
						$totalDataPackDiscountedPrice=0;
						$totalNewVersionDiscountedPrice=0;
						$total_new_version_discounted_price=0;
						$discounted_price = 0;
						$rep_data_pack_price=@$getPrices['OriginalDatapack'];
						
						if($licenceType == 'Single User License') {
							if($rep_data_pack_price>0){ $discounted_price = 80;	 }
							if($upcomingPublishedStatus === 'P' && $reportAge>6 && $reportPrice>=2000){
								$discounted_price_new_version = 70;	
							}
						}
						else if($licenceType == 'Five User License') {
							if($rep_data_pack_price>0){ $discounted_price = 85;	 }
							if($upcomingPublishedStatus === 'P' && $reportAge>6 && $reportPrice>=2000){
								$discounted_price_new_version = 72;	
							}
						}
						/* Code For Data Pack Starts */						
						if($discounted_price>0) {
							$data_pack_discounted_price = ceil(urep_price($rep_data_pack_price,$discounted_price));
							$totalDataPackDiscountedPrice = $totalDataPackDiscountedPrice + $data_pack_discounted_price;
						}
						if(($licenceType == 'Single User License' || $licenceType == 'Five User License') && ($reportPrice > 2000) && ($upcomingPublishedStatus == 'P' || $upcomingPublishedStatus == 'U')  && $totalDataPackDiscountedPrice>0) {
							$isdatapack	= preg_replace('/\s+/', ' ', @$input['data_pack_'.$rowId]);
							if($isdatapack=='data_pack') {
								$actualDatapackPrice=$actualDatapackPrice+$totalDataPackDiscountedPrice;
								$subTotal=$subTotal+$data_pack_discounted_price;
							}
						}
						/* Code For Data Pack Ends */
						/* Code For New Version Request Starts */
						if($discounted_price_new_version>0) {
							$newVersionDiscountedPrice = ceil(urep_price($reportPrice/2,$discounted_price_new_version));
							$totalNewVersionDiscountedPrice = $totalNewVersionDiscountedPrice + $newVersionDiscountedPrice;
						}
						if(($licenceType == 'Single User License' || $licenceType == 'Five User License' || $licenceType == 'Enterprise User License') && $reportPrice > 2000 && $upcomingPublishedStatus == 'P' && $reportAge != '' && $reportAge>6 && $totalNewVersionDiscountedPrice>0)  {
							$new_version_pack	= preg_replace('/\s+/', ' ', @$input['new_version_pack'.$rowId]);
							if($new_version_pack=='new_version_pack') 
							{
								$actualNewVersionPrice=$actualNewVersionPrice+$totalNewVersionDiscountedPrice;
								$subTotal=$subTotal+$newVersionDiscountedPrice;
							}
						}
						/* Code For New Version Request Ends */
					}
					$performCheck="No";
					if($performCheck=='No') {
						$isvalidCoupon=$this->CheckoutModel->isvalidCoupon($CouponCode);
						if(!empty($isvalidCoupon)) {
							if($cartTotal>0) {
								if($cartTotal>=$isvalidCoupon->minimumPurchase) 
								{
									if($isvalidCoupon->couponType=='Percentage') {
										$couponDiscountPrice=round(($cartTotal*$isvalidCoupon->couponDiscount)/100);
										$couponDiscountPerORFlat=$isvalidCoupon->couponDiscount."%";
									}
									else if($isvalidCoupon->couponType=='Flat') {
										$couponDiscountPrice=$isvalidCoupon->couponDiscount;
										$couponDiscountPerORFlat=$isvalidCoupon->couponDiscount." Flat";
									}
									if($couponDiscountPrice>0) { $Message = 'Coupon applied successfully.'; }
								}
								else
								{
									$Message = 'Purchase of minimun '.$isvalidCoupon->minimumPurchase." is required to avail the offer." ;
								}							
							}						
						}
					}
					$subTotal=floatval($subTotal);
					$couponDiscountPrice=floatval($couponDiscountPrice);					
					$checkCouponprice=$subTotal-$couponDiscountPrice;					
					if($checkoutLink=='manual' && $manualInternetHandlingCharge==0) {
						$internetHandling=0;					
					}
					else if ($checkCouponprice <= 2500 && $checkCouponprice > 0)  {
						$internetHandling = ceil((5 * $checkCouponprice) / 100);
					}
					elseif ($checkCouponprice > 2500 && $checkCouponprice <= 4000)  {
						$internetHandling = ceil((2.5 * $checkCouponprice) / 100);	
					}
					$internetHandling=floatval($internetHandling);					
				}
				$gstAmount=0;
				$gstTotal =  $subTotal-$couponDiscountPrice+$internetHandling;
				if($country=='India') {
					$gstAmount=ceil((18 * $gstTotal) / 100);
				}
				if($checkoutId == '12c4f9a17325428b814737f1883ed02f')
				{
					$grand_total = '993';
				}
				else
				{
					$grand_total=number_format(($subTotal+$gstAmount-$couponDiscountPrice+$internetHandling), 2, '.', '');
				}
				
				$orderalreadyExists=$this->CheckoutModel->checkOrderExists($checkCheckoutExists->id,false);
				if(empty($orderalreadyExists)) {
					$order_no = 'ORD'.sprintf("%08d",rand('00000000','999999'));
					$insert_data = array(
					'order_no' => $order_no,
					'checkout_id'=>$cart_id,
					'full_name'=>$name,
					'email'=> $email,
					'company'=>$company,
					'phone'=>$countrycode.' '.$contact_no,
					'discount_note'=>$data_pack_discount,
					'address'=>$address,
					'state'=>$state,
					'city'=>$city,
					'country'=>$country,
					'job_role'=>$jobrole,
					'gstno'=>$gstno,
					'zip_postal'=>$zip_code,
					'cart_contents'=>$checkCheckoutExists->cart_contents,
					'date'=>date("Y-m-d H:i:s"),
					'email_new_checkout_attempt' => 1,
					'email_new_payment_attempt' => 0,
					'couponDiscountPercentage'=>$couponDiscountPerORFlat,
					'couponPrice'=>$couponDiscountPrice,
					'couponCode'=>$CouponCode,
					'grandTotal' => $grand_total,
					'cartTotal'=>$cartTotal,
					'datapackAmount'=>$actualDatapackPrice,
					'newVersionAmount'=>$actualNewVersionPrice,
					'subTotal'=>$subTotal,
					'internetHandling'=>$internetHandling,
					'gstAmount'=>$gstAmount);
					$orderId=$this->CheckoutModel->addOrder($insert_data);
				}
				else{
					$order_no=$orderalreadyExists->order_no;
					$orderId=$orderalreadyExists->id;
					$updateOrderDetails = array(					
					'full_name'=>$name,
					'email'=> $email,
					'company'=>$company,
					'phone'=>$countrycode.' '.$contact_no,
					'discount_note'=>$data_pack_discount,
					'address'=>$address,
					'state'=>$state,
					'city'=>$city,
					'country'=>$country,
					'job_role'=>$jobrole,
					'gstno'=>$gstno,
					'zip_postal'=>$zip_code,
					'cart_contents'=>$checkCheckoutExists->cart_contents,
					'date'=>date("Y-m-d H:i:s"),					
					'couponDiscountPercentage'=>$couponDiscountPerORFlat,
					'couponPrice'=>$couponDiscountPrice,
					'couponCode'=>$CouponCode,
					'grandTotal' => $grand_total,
					'cartTotal'=>$cartTotal,
					'datapackAmount'=>$actualDatapackPrice,
					'newVersionAmount'=>$actualNewVersionPrice,
					'subTotal'=>$subTotal,
					'internetHandling'=>$internetHandling,
					'gstAmount'=>$gstAmount);	
					$this->CheckoutModel->updateOrder($updateOrderDetails,$order_no);
				}
				return redirect('checkout-confirm/'.md5($orderId));
			}
			else{
				return redirect($input['return_url'])->withErrorMessage('Invalid Parameters Submitted.')->withInput();
			}			
		}
	}
	function checkoutConfirm($orderId,Request $request)
	{		
		if($orderId!='') {
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
			if(!empty($orderDetails)) {
				$pageData['metaTitle']="Checkout : Allied Market Research";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";  
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";       
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['headerUrl'] = "Request Call Back";		
        		$pageData['headerLink'] = "request-call-back";
				$pageData['order_no'] = $orderDetails->order_no;
				return view('pages.checkout-confirm',$pageData);
			}
			else
			{
				return redirect('shopping-cart');
			}
		} 
	}
	function checkoutConfirmProcess(Request $request)
	{
		$input = $request->all();
		$messages = [
			'_token.required' => 'Invalid parameters submitted, please refresh the page',
			'refererId.required' => 'Invalid parameters submitted, please refresh the page',
			'return_url.required' => 'Invalid parameters submitted, please refresh the page',
			'pay_mode.required' => 'Invalid parameters submitted, please refresh the page'
		];			
		$validator = Validator::make($input,['_token' => 'required','refererId' => 'required','return_url' => 'required','pay_mode' => 'required'],$messages);
		if($validator->fails()){            
			return redirect($input['return_url'])->withErrors($validator)->withInput();
		}
		else {
			$pay_mode = preg_replace('/\s+/', ' ', $input['pay_mode']);
			$orderId = preg_replace('/\s+/', ' ', $input['refererId']);
			if ($pay_mode == "checkout") 		{ $val = "2co"; } 
			else if ($pay_mode == "paypal") 	{ $val = "Paypal"; } 
			else if ($pay_mode == "hdfc")   	{ $val = "HDFC"; } 
			else if ($pay_mode == "ccavenue") 	{ $val = "CCAvenue";} 
			else if ($pay_mode == "wire") 		{ $val = "Pay by Invoice"; } 
			else if ($pay_mode == "2co") 		{ $val = "2co"; }
			if($val!='') {				
				$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
				if(!empty($orderDetails)) {
					Session::put('orderId', $orderDetails->order_no);
					$updateData['query_type']=$val;
					$updateData['email_new_payment_attempt']=1;					
					$this->CheckoutModel->updateOrder($updateData,$orderDetails->order_no);					
					if($pay_mode == 'wire'){						
						return redirect('review-invoice/'.$orderId);
					}
					else if($pay_mode == 'ccavenue'){						
						return redirect('go_ccavenue/'.$orderId);
					}
					else if($pay_mode == 'hdfc'){						
						return redirect('go_hdfc/'.$orderId);
					}					
					else if($pay_mode == 'checkout'){						
						return redirect('go_2co/'.$orderId); 
					}
					else if($pay_mode == 'paypal'){
						$provider = new ExpressCheckout;
						$recurring = false;
						$cart = $this->getCheckoutData($recurring,$orderDetails->order_no);
						try {
							$response = $provider->setExpressCheckout($cart, $recurring);
							return redirect($response['paypal_link']);
						} catch (\Exception $e) {
							$invoice = $this->createInvoice($cart, 'Invalid');
							session()->put(['code' => 'danger', 'message' => "Error processing PayPal payment for Order $invoice->id!"]);
						}
					}
					else{
						return redirect($input['return_url'])->withErrorMessage('Invalid Payment Mode Selected.')->withInput();
					}
				}
				else {            
					return redirect('shopping-cart')->withErrorMessage('Invalid Order Id.');
				}
			}
			else {            
				return redirect($input['return_url'])->withErrors($validator)->withInput();
			}
		}		
	}
	function reviewInvoice($orderId,Request $request) {
		if($orderId!='') {
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
			if(!empty($orderDetails)) {
				$pageData['metaTitle']="Review Your Invoice - AMR";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";       
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";  
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['order_no'] = $orderDetails->order_no;
				return view('pages.review-invoice',$pageData);
			}
			else
			{
				return redirect('shopping-cart');
			}
		} 
	}
	function checkoutThanks($orderId,Request $request) {
		if($orderId!='') {
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
			if(!empty($orderDetails)) {
				$pageData['metaTitle']="Checkout Thanks";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";   
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";      
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['order_no'] = $orderDetails->order_no;
				return view('pages.checkout_thanks',$pageData);
			}
			else
			{
				return redirect('shopping-cart');
			}
		} 
	} 
	function downloadInvoice($orderId,Request $request) {
		$Status="Failed";
		if($orderId!='') {
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
			if(!empty($orderDetails)) {
				$pageData['metaTitle']="Get Your Invoice - AMR";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";    
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";     
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['order_no'] = $orderDetails->order_no;
				$id=0;
				$name="";
				$licenseType="";
				if(count($cartContent)>0)
				{
					foreach($cartContent as $items) {
						$id = $items["id"];
						$name =  $items["name"];
						$price =  $items["price"];
						$licenseType = $items['options']['license'];
					}					
					$reportInfo=$this->ReportsModel->getSingleReportDetails($id,false);
					$catcode='';
					if($reportInfo->rep_report_code!=''){
						list($catcode,$catcoid) = (explode('_',$reportInfo->rep_report_code)) ? explode('_',$reportInfo->rep_report_code): explode(' ',$reportInfo->rep_report_code);
					}				
					$query_code = 'AMRQ-WT-'.$catcode.rand();
					$curlPostData=array(
						'website_id' 		=> 1,
						'report_title'		=> html_entity_decode($name),
						'full_name'			=> $orderDetails->full_name,
						'company_name'		=> $orderDetails->company,
						'job_role'			=> $orderDetails->job_role,
						'business_email'	=> $orderDetails->email,
						'country_name'		=> $orderDetails->country,
						'contact_number'	=> $orderDetails->phone,
						'message'			=> 'Wire Transfer',
						'report_id' 		=> $id,
						'report_url'		=> $reportInfo->rep_url,
						'publish_status' 	=> $reportInfo->rep_upcoming_published_status, 
						'category_name' 	=> html_entity_decode($reportInfo->cat_title),
						'category_id' 		=> $reportInfo->rep_sub_cat_1_id,
						'subcategory_name' 	=> html_entity_decode($reportInfo->subcat_name),
						'subcategory_id' 	=> $reportInfo->rep_sub_cat_2_id, 
						'report_code' 		=> $reportInfo->rep_report_code,
						'price' 			=> $reportInfo->rep_price,
						'datapack_price' 	=> $orderDetails->datapackAmount,
						'gst_val' 			=> $orderDetails->gstAmount,
						'grandTotal'		=> $orderDetails->grandTotal,
						'currentStage' 		=> 'Introduction',
						'request_type'		=> 'Checkout Attempt',
						'query_code' 		=> $query_code,
						'licenseType' 		=> $licenseType);
					$getinvoiceDetails=$this->salesEffortsCurlRequest($this->curlURL,$curlPostData);
							$phone = explode(' ',$orderDetails->phone);
					$invoicefinal_data = array(
						'invoiceNumber' => ' ',
						'queryCode'	=> $query_code,
						'userId' => 25,
						'managerUsedId' => null,
						'activitiesId' => 8,
						'invoiceTypeId' => 2,
						'proformaInvoiceId' => 0,
						'paymentModeId' => 5,
						'bankId' => 1,
						'paymentGatewayId' => 5,
						'date' => date("Y-m-d"),
						'leadSource' => 49,
						'licenseType' => 3,
						'typeOfSale' => 1,
						'organizationName' => $orderDetails->company,
						'userName' => $orderDetails->full_name,
						'email' => $orderDetails->email,
						'jobRole' => $orderDetails->job_role,
						'addressLine1' => $orderDetails->address,
						'addressLine2' => ' ',
						'country' => $orderDetails->country,
						'state' => $orderDetails->state,
						'city' => $orderDetails->city,	
						'postalCode' => $orderDetails->zip_postal,
						'telephone' => $phone[1],
						'organizationName_billing' => $orderDetails->company,
						'userName_billing' => $orderDetails->full_name,
						'email_billing' => $orderDetails->email,
						'jobRole_billing' => $orderDetails->job_role,
						'addressLine1_billing' => $orderDetails->address,
						'addressLine2_billing' => ' ',
						'country_billing' => $orderDetails->country,
						'state_billing' => $orderDetails->state,
						'city_billing' => $orderDetails->city,
						'postalCode_billing' => $orderDetails->zip_postal,
						'telephone_billing' => $phone[1],
						'reportTitle' => ' ',
						'reportCode' => ' ',
						'reportCategory' => ' ',
						'reportSubcategory' => ' ',
						'orginalPrice' => $orderDetails->subTotal,
						'discountPrice' => $orderDetails->couponPrice,
						'subTotal' => floatval($orderDetails->subTotal) - floatval($orderDetails->couponPrice),
						'GST' => $orderDetails->gstAmount,
						'grossPrice' => floatval($orderDetails->subTotal) - floatval($orderDetails->couponPrice) + floatval($orderDetails->gstAmount),
						'internetHandlingFee' => $orderDetails->internetHandling,
						'dataPack_amt' => $orderDetails->datapackAmount,
						'totalGrand' => $orderDetails->grandTotal,
						'amrshare' => null,
						'partnershare' => null,
						'invoiceStatusId' => 2,
						'dispatchStatusId' => 0,
						'researchStatusId' => 0,
						'accountsStatusId' => 2,
						'clientNote' => ' ',
						'dispatchNote' => ' ',
						'accountNote' => ' ',
						'salesNote' => ' ',
						'checkOutId' => $orderDetails->checkout_id,
						'xlsformat' => 1,
						'pdf' => 1,
						'cdrom' => 0,
						'hardcopy' => 0,
						'onlineonly' => ' ',
						'isDeleted' => 1,
						'reportId' => ' ',
						'doumentType' => 'Invoice',
						'siteName' => 'Allied Market Research',
						'subscriptionType' => 0,
						'dispatchToMailId' => $orderDetails->email,
						'dispatchCcMailId' => ' ',
						'dispatchMessege' => ' ',
						'dispatchFilePath' => ' ',
						'dispatchDate' => ' ',
						'dispatchTime' => ' ',
						'paymentDate' => null,
						'iscancel' => 1,
						'regionId' => 0,
						'channelTypeId' => 0,
						'CancelReason' => null,
						'created_at' => date('Y-m-d H:i:s'),
						'updated_at' => date('Y-m-d H:i:s')
					);
					$invoiceReport_data = $orderDetails->cart_contents;
					$email = $orderDetails->email;
					$full_name = $orderDetails->full_name;

					$mysqlCon = \DB::connection('mysql4');
					$invoiceID = $mysqlCon->table('invoice_proformas')->insertGetId($invoicefinal_data);
					// $invoice_number = 'AMR'.date('Y').date('m') . $invoiceID;
					$invoice_number = 'PRO-AMR'.date('Y').date('m').$invoiceID;
					$result = $mysqlCon->table('invoice_proformas')
			                ->where('invoiceId', $invoiceID)
							->update(['invoiceNumber' => $invoice_number]);
									foreach (unserialize($invoiceReport_data) as $reportData){

					$moreReportData = DB::table('bmr_report')
		                ->join('categories', 'bmr_report.rep_sub_cat_1_id', '=', 'categories.cat_id')
		                ->join('subcategory', 'bmr_report.rep_sub_cat_2_id', '=', 'subcategory.subcat_id')      
		                ->where('bmr_report.rep_id',$reportData['id'])   
		                ->select('bmr_report.rep_report_code', 'categories.cat_title','subcategory.subcat_name')
						->first();
					if(empty($moreReportData))
					{
						$moreReportData = DB::table('bmr_report_cp')
							->join('categories', 'bmr_report_cp.rep_sub_cat_1_id', '=', 'categories.cat_id')   
							->where('bmr_report_cp.rep_id',$reportData['id'])   
							->select('bmr_report_cp.cp_code as rep_report_code', 'categories.cat_title')
							->first();
						if(empty($moreReportData)){
							$rawData['rep_report_code'] = $reportData['options']['reportCode'];
							$rawData['cat_title'] = 0;
							$rawData['subcat_name'] = 0;
							$moreReportData = (object) $rawData;

						}else{
							$moreReportData->subcat_name = 0;
						}
					}	
					$insertInvoiceReport = array(
						'invoiceNumber' => $invoice_number,
						'reportId' => 0,
						'reportTitle' => $reportData['name'],
						'reportCode' => $moreReportData->rep_report_code,
						'reportCategory' => $moreReportData->cat_title,
						'reportSubcategory' => $moreReportData->subcat_name,
						'reportPrice' => $reportData['price'],
						'xlsformat' => 0,
						'pdf' => 1,
						'cdrom' => 0,
						'hardcopy' => 0,
						'onlineonly' => 0,
						'licenseTypeName' => $reportData['options']['license'],
						'report_id' => $reportData['id'],
						'report_status' => $reportData['options']['pu_status'],
						'updatedReportPrice' => 0,
						'dataPrice' => 0
					);
					$mysqlCon->table('invoice_reports')->insert($insertInvoiceReport);  
				}
					$invoice = $mysqlCon->table('invoice_proformas')
			                ->join('activities', 'invoice_proformas.activitiesId', '=', 'activities.activitiesId')
			                ->join('invoice_types', 'invoice_proformas.invoiceTypeId', '=', 'invoice_types.invoiceTypeId')
			                ->join('lead_sources', 'invoice_proformas.leadSource', '=', 'lead_sources.leadSourceId')   
			                ->join('tbl_license_type', 'invoice_proformas.licenseType', '=', 'tbl_license_type.licenseTypeId') 
			                ->join('type_of_sales', 'invoice_proformas.typeOfSale', '=', 'type_of_sales.typeOfSaleId')      
			                ->join('payment_modes', 'invoice_proformas.paymentModeId', '=', 'payment_modes.paymentModeId')        
			                ->where('invoice_proformas.invoiceId',$invoiceID)   
			                ->select('invoice_proformas.*', 'invoice_types.invoiceTypeName', 'lead_sources.leadSourceTitle','activities.activitiesTitle','tbl_license_type.licenseTypeTitle','type_of_sales.typeOfSaleTitle','payment_modes.paymentModeTitle')
							->first();
					$invoice_report = $mysqlCon->table('invoice_reports')->where('invoiceNumber',"=",$invoice->invoiceNumber)->get();
					
					$data = array(
						'invoice' => $invoice,
						'invoice_report' => $invoice_report
					);
					if(!empty($data))
					{						
						$invoiceNumber 	= $invoice->invoiceNumber;
						$invoice_no 	= $invoice->invoiceId; 
						$file_name 		= $invoice->invoiceNumber;						
						$subject		='Invoice '.$invoiceNumber;
						$full_name		=$orderDetails->full_name;
						$pageData['full_name']=$full_name;
						$pageData['invoiceNumber']=$file_name;
						$pageData['invoiceId']=$invoice_no;
						PDF::html('pages.download-invoice',$pageData,$file_name);	
						$attachmentLink= public_path("/assets/invoices/$file_name".".pdf");
						$userEmail=$invoice->email;						
						Mail::send('mailTemplates.wiretransferUserEmail', $pageData, function($message) use ($userEmail,$full_name,$attachmentLink,$subject){    
							$message->from('purchase@alliedmarketresearch.com','Allied Market Research')
								->to($userEmail,$full_name)								
								->subject($subject)
								->attach($attachmentLink);
						});
						$Status="Success";
					}					
				}				
				if($Status=='Success') {
					$updateArray = array(
						"transactionStatus" => 'In Progress',
						"transactionDetails" => $attachmentLink,
					);
					DB::table('amr_cp_orders')
							->where('id', $orderId)
							->update($updateArray);
					if($orderDetails->couponCode!='' && $orderDetails->couponPrice > 0)
						{
							$subjectLine = 'Success:: Payment Successful through CCavenue. Order No: ';
							$isExist =  DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->first();
							if($isExist)
							{
								/*DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->update(['isUsed' => 'Yes']);*/	
							} 
						}
				 	return redirect('contact-us/thanks');
				}
				else{
					return redirect('shopping-cart');
				}		
			}
			else
			{
				return redirect('shopping-cart');
			}
		} 
	}
	function salesEffortsPaymentReceived($url,$postData) {
		$result=array();
		if($url!='' && !empty($postData) && $postData!='') {
			$payload = json_encode($postData);
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$result = curl_exec($ch);
			curl_close($ch); 
		}
		return $result;
	}
	function salesEffortsCurlRequest($url,$postData) {
		$resultarray=array();
		if($url!='' && !empty($postData) && $postData!='')
		{
			$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_URL => $url,
				CURLOPT_USERAGENT => 'AMR Request',
				CURLOPT_POST => 1,
				CURLOPT_POSTFIELDS => $postData
			));
			$resp = curl_exec($curl);
			$resultarray = json_decode($resp, true);  			
			curl_close($curl);
		}
		return $resultarray;
	}
	function ccavenueLoading($orderId,Request $request)
	{
		if($orderId!='') 
		{
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
			if(!empty($orderDetails)) 
			{
				$pageData['metaTitle']="Pay Using CCAvenue - AMR";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";   
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";      
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['order_no'] = $orderDetails->order_no;
				$merchant_data='';
				$working_key='5DDC438F030A3344C53D260B8D1FD157';
				$access_code='AVLM68EA84BN77MLNB';
				$pageData['access_code'] = $access_code;
				$today=date('YmdHi');
				$_request['tid']=rand((int)$orderDetails->order_no, $today);
				$_request['merchant_id']='121669';
				$_request['order_id']=$orderDetails->id;
				$_request['amount']=$orderDetails->grandTotal;
				$_request['currency']='USD';
				$_request['redirect_url']=url('api/ccavenue-callback');
				$_request['cancel_url']=url('api/ccavenueCancel/'.$orderDetails->id);
				$_request['language']='EN';
				foreach ($_request as $key => $value)
				{
					$merchant_data.=$key.'='.$value.'&';
				}
				$encrypted_data=$this->encrypt($merchant_data,$working_key); // Method for encrypting the data.
				$pageData['encrypted_data'] = $encrypted_data;
				return view('paymentGateway.goto_ccavenue',$pageData);
				// return view('pages.paypal-loading',$pageData);
			}
			else
			{
				return redirect('shopping-cart');
			}
		} 
	}
	function encrypt($plainText,$key)
	{
	  $key = $this->hextobin(md5($key));
	  $initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
	  $openMode = openssl_encrypt($plainText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
	  $encryptedText = bin2hex($openMode);
	  return $encryptedText;
	}
	function decrypt($encryptedText,$key)
	{
	  $key = $this->hextobin(md5($key));
	  $initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
	  $encryptedText = $this->hextobin($encryptedText);
	  $decryptedText = openssl_decrypt($encryptedText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
	  return $decryptedText;
	}
	function hextobin($hexString)
	{
		$length = strlen($hexString);
		$binString="";
		$count=0;
		while($count<$length)
		{
			$subString =substr($hexString,$count,2);
			$packedString = pack("H*",$subString);
			if ($count==0)
			{
				$binString=$packedString;
			}
			else
			{
				$binString.=$packedString;
			}
			$count+=2;
		}
		return $binString;
	}
	public function ccavenueCancel (Request $request) 
	{
		if ($request->isMethod('post')) 
		{
			$responseArray = array();
			$keyArray = array();
			$valueArray = array();
			$working_key='5DDC438F030A3344C53D260B8D1FD157';
			$orderId = $request->orderNo;
			$encrypted_data=$this->decrypt($request->encResp,$working_key); 
			$data =  explode("&",$encrypted_data);
			for($i=0;$i<count($data);$i++){
				$add =  explode("=",$data[$i]);
				array_push($keyArray,$add[0]);
				array_push($valueArray,$add[1]);
				$add = '';$temparray = array();
			}
			$responseArray = array_combine($keyArray,$valueArray);
			$updateArray = array(
				"transactionStatus" => $responseArray['order_status'],
				"transactionDetails" => serialize($responseArray),
			);
			DB::table('amr_cp_orders')
					->where('id', $orderId)
					->update($updateArray);
			if($orderId !='') 
			{
				$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,false);
				if(!empty($orderDetails)) 
				{
					$pageData['metaTitle']="Pay Using CCAvenue - AMR";
					$pageData['metaDescription']=""; 
					$pageData['metaKeyword']="";  
					$pageData['headerUrl'] = "Become a Reseller";		
        			$pageData['headerLink'] = "become-a-reseller";       
					$pageData['getCategories']=$this->HomeModel->get_active_categories();
					$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
					$pageData['getCountry']=$this->CmsFooterModel->get_countries();
					$pageData['orderDetails'] = $orderDetails;
					$cartContent=unserialize($orderDetails->cart_contents);								
					$pageData['cartContent'] = $cartContent;				
					$pageData['checkoutId'] = $orderDetails->checkout_id;
					$pageData['order_no'] = $orderDetails->order_no;
					$pageData['orderId'] = $orderId;
					return view('paymentGateway.cancel_ccavenue',$pageData);
				}
				else
				{
					return redirect('shopping-cart');
				}
			}
		}
		else
		{
			return redirect('shopping-cart');
		}
	}		
	public function ccavenueCallback(Request $request) 
	{
		if ($request->isMethod('post')) 
		{
			$responseArray = array();
			$keyArray = array();
			$valueArray = array();
			$reportLicence = array();
			$working_key='5DDC438F030A3344C53D260B8D1FD157';
			$orderId = $request->orderNo;
			$encrypted_data=$this->decrypt($request->encResp,$working_key); 
			$data =  explode("&",$encrypted_data);
			for($i=0;$i<count($data);$i++){
				$add =  explode("=",$data[$i]);
				array_push($keyArray,$add[0]);
				array_push($valueArray,$add[1]);
				$add = '';$temparray = array();
			}
			$responseArray = array_combine($keyArray,$valueArray);
			$updateArray = array(
				"transactionStatus" => $responseArray['order_status'],
				"transactionDetails" => serialize($responseArray),
			);
			DB::table('amr_cp_orders')
					->where('id', $orderId)
					->update($updateArray);
			if($orderId !='') 
			{
				$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,false);
				foreach (unserialize($orderDetails->cart_contents) as $reportData) 
				{
					$reportId []= $reportData['id'];
					$reportTitles [] = $reportData['name'];
					if(!in_array($reportData['options']['license'],$reportLicence, true)){
						$reportLicence [] = $reportData['options']['license'];
					}
				}
				$reportTitles = implode(',',$reportTitles);
				$reportLicence = implode(',',$reportLicence);
				if(!empty($orderDetails)) {
					$pageData['metaTitle']="Thank You for Using - AMR";
					$pageData['metaDescription']=""; 
					$pageData['metaKeyword']=""; 
					$pageData['headerUrl'] = "Become a Reseller";		
        			$pageData['headerLink'] = "become-a-reseller";        
					$pageData['getCategories']=$this->HomeModel->get_active_categories();
					$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
					$pageData['reportDetails']=$this->ReportsModel->showReportPreview($reportId);
					$pageData['getCountry']=$this->CmsFooterModel->get_countries();
					$pageData['orderDetails'] = $orderDetails;
					$cartContent=unserialize($orderDetails->cart_contents);								
					$pageData['cartContent'] = $cartContent;				
					$pageData['checkoutId'] = $orderDetails->checkout_id;
					$pageData['order_no'] = $orderDetails->order_no;
					$pageData['orderId'] = $orderId;
					$pageData['orderStatus'] = $responseArray['order_status'];
					$pageData['transactionDetails'] = $responseArray;
					$pageData['reportTitles'] = $reportTitles;
					$pageData['reportLicence'] = $reportLicence;
					$orderNo = $orderDetails->order_no;
					$orderStatus = $responseArray['order_status'];					
					$paymentDoneBYStudent='No';	
					$isCampaignURL='';
					$campaignCode='';
					$campaignBy='';					
					if($responseArray['order_status'] == 'Success')
					{
						Cart::destroy();
						if($orderDetails->couponCode!='' && $orderDetails->couponPrice > 0)
						{
							$subjectLine = 'Success:: Payment Successful through CCavenue. Order No: ';
							$isExist =  DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->first();
							if($isExist)
							{
								/*DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->update(['isUsed' => 'Yes']);	*/
							} 
						}
						$subjectLine = 'Success:: Payment Successful through CCavenue. Order No: ';						
						/* code by krutik on 23/9/2019 */
						$fullpurchase = true;
						/* code by krutik ends here */
						$checkStudentCampaign =  DB::table('studentsCampaign')->where('checkoutID', $orderDetails->checkout_id)->first();					
						if(!empty($checkStudentCampaign)) 
						{
							DB::table('studentsCampaign')->where('checkoutID', $orderDetails->checkout_id)->update(['ispaymentReceived' => 'Yes']);
							DB::table('amr_query_formdata')->where('query_code', $checkStudentCampaign->queryCode)->update(['amr_automail_status' => 'N']);
							$paymentDoneBYStudent='Yes';
							/* code by krutik on 23/9/2019 */
							if($checkStudentCampaign->queryCode != 'all'){
								$fullpurchase = false;
							}
							/* code by krutik ends here */
							$this->setCloseAndWon($checkStudentCampaign->queryCode);
						}						
						$data = array(
						'checkoutId' => $orderDetails->checkout_id,
						'Total' => $responseArray['amount'],
						'gateway' => 'ccavenue'); 
														
						$this->salesEffortsPaymentReceived($this->paymentReceivedURL,$data);
						/* code by krutik on 23/9/2019 */
						$checkoutData =  DB::table('amr_checkout')->select('campaignCode', 'paymentThrough')->where('id', $orderDetails->checkout_id)->first();
						$checkCampaignCode =  $checkoutData->campaignCode;
						$paymentFrom =  $checkoutData->paymentThrough;
						if(empty($checkCampaignCode->campaignCode)){
							$this->generateInvoice($fullpurchase, $orderDetails, 4, $paymentDoneBYStudent, $paymentFrom, $chapters);
							if($paymentFrom == 'Reportviewer'){
								$this->updateReportViewer($orderDetails->checkout_id, $orderNo);
							}
							
						}else{
							$this->customPaymentMail($orderDetails, 'CCAVENUE'); // Custom Payment
						}	
						/* code by krutik ends here */					
					}
					else
					{
						$subjectLine = 'Failed:: Payment Unsuccessful through CCavenue. Order No: ';
					}
					
					
					$emailData['orderDetails'] = $orderDetails;
					$emailData['Amount'] = $responseArray['amount'];
					$emailData['paymentDoneBYStudent'] = $paymentDoneBYStudent;
					$emailData['isCampaignURL'] = $isCampaignURL;
					$emailData['campaignCode'] = $campaignCode;
					$emailData['campaignBy'] = $campaignBy;
					Mail::send('mailTemplates.CCavenuePaymentSuccess', $emailData, function($message) use ($orderNo,$subjectLine){ 
						$message->from('purchase@alliedmarketresearch.com','Allied Market Research')
								->to('krutik.dhameliya@alliedanalytics.com','Allied Market Research Payment')									
								->subject($subjectLine.$orderNo);	
					});

					return view('paymentGateway.success_ccavenue',$pageData);
				}
				else
				{
					return redirect('shopping-cart');
				}
			}
		}
		else
		{
			return redirect('shopping-cart');
		}
	}
	public function hdfcLoading($orderId,Request $request) {
		if($orderId!='') {
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
			if(!empty($orderDetails)) {
				$pageData['metaTitle']="Pay Using HDFC - AMR";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";   
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";      
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['order_no'] = $orderDetails->order_no;
				return view('paymentGateway.goto_hdfc',$pageData);
			}
			else
			{
				return redirect('shopping-cart');
			}
		} 
	}
	public function hdfcThanks(Request $request,$orderId) 
	{
		if($request->isMethod('post')) 
		{
					
			$pageData['metaTitle']="Thank you";
			$pageData['metaDescription']=""; 
			$pageData['metaKeyword']="";     
			$pageData['headerUrl'] = "Become a Reseller";		
			$pageData['headerLink'] = "become-a-reseller";    
			$pageData['getCategories']=$this->HomeModel->get_active_categories();
			$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
			$pageData['getCountry']=$this->CmsFooterModel->get_countries();			
			$totalAmount=0;			
			$hashData='b1c8e672ccfb230b6c17d1ceedd67ddb';
			$ResponseMessage='';
			$hashTotal=0;
			$cont_person='';
			$address='';
			$city='';
			$state='';
			$zcode='';
			$country_name='';
			$cont_no='';
			$email='';	
			$checkoutId=0;			
				
			$input = $request->all();	
			if(count($input)>0)
			foreach ($input as $key => $value){
				if (strlen($value) > 0 && $key != 'SecureHash') { $hashData .= '|'.$value; }
				if (strlen($value) > 0 && $key == 'ResponseMessage') { $ResponseMessage= $value;	 }
				if (strlen($value) > 0 && $key == 'Amount') { $hashTotal=$totalAmount=$value; }
				if (strlen($value) > 0 && $key == 'BillingName') { $cont_person .= $value; }
				if (strlen($value) > 0 && $key == 'BillingAddress') { $address .= $value;	 }
				if (strlen($value) > 0 && $key == 'BillingCity') { $city .= $value; }
				if (strlen($value) > 0 && $key == 'BillingState') { $state .= $value; }
				if (strlen($value) > 0 && $key == 'BillingPostalCode') { $zcode .= $value; }
				if (strlen($value) > 0 && $key == 'BillingCountry') { $country_name .= $value; }
				if (strlen($value) > 0 && $key == 'BillingPhone') { $cont_no .= $value; }
				if (strlen($value) > 0 && $key == 'BillingEmail') { $email .= $value; }				
			}
			$orderDetails=$this->CheckoutModel->getOrderRow($orderId);
			
			$pageData['ResponseMessage']=$ResponseMessage;
			$pageData['orderDetails']=$orderDetails;
			
			$subjectLine = 'Failed:: Payment Unsuccessful through HDFC. Order No: ';
			$paymentDoneBYStudent='No';
			$isCampaignURL='';
			$campaignCode='';
			$campaignBy='';
			if($ResponseMessage == 'Transaction Successful')
			{
				$subjectLine = 'Success:: Payment successful through HDFC. Order No: ';		
				if($orderDetails->couponCode!='' && $orderDetails->couponPrice > 0)
				{
					$isExist =  DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->first();
					if($isExist)
					{
						/* DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->update(['isUsed' => 'Yes']); */	
					} 
				}
				$checkoutId=$orderDetails->checkout_id;
				/* code by krutik on 23/9/2019 */
				$fullpurchase = true;
				/* code by krutik ends here */
				$checkStudentCampaign =  DB::table('studentsCampaign')->where('checkoutID', $checkoutId)->first();	
				if(!empty($checkStudentCampaign)) 
				{
					DB::table('studentsCampaign')->where('checkoutID', $checkoutId)->update(['ispaymentReceived' => 'Yes']);
					DB::table('amr_query_formdata')->where('query_code', $checkStudentCampaign->queryCode)->update(['amr_automail_status' => 'N']);
					$this->setCloseAndWon($checkStudentCampaign->queryCode);
					/* code by krutik on 23/9/2019 */
					$paymentDoneBYStudent='Yes';
					if($checkStudentCampaign->queryCode != 'all'){
						$fullpurchase = false;
					}
					/* code by krutik ends here */
				}

				
				$data = array('checkoutId' => $checkoutId,'Total' => $totalAmount,'gateway' => 'HDFC');						
				$this->salesEffortsPaymentReceived($this->paymentReceivedURL,$data);	
				/* code by krutik on 23/9/2019 */
				$checkoutData =  DB::table('amr_checkout')->select('campaignCode', 'paymentThrough')->where('id', $orderDetails->checkout_id)->first();
				$checkCampaignCode =  $checkoutData->campaignCode;
				$paymentFrom =  $checkoutData->paymentThrough;	
				if(empty($checkCampaignCode->campaignCode)){
					$this->generateInvoice($fullpurchase, $orderDetails, 3, $paymentDoneBYStudent, $paymentFrom, $chapters);
					if($paymentFrom == 'Reportviewer'){
						$this->updateReportViewer($orderDetails->checkout_id, $orderNo);
					}
					
				}else{
					$this->customPaymentMail($orderDetails, 'HDFC'); // Custom Payment
				}	
				/* code by krutik ends here */
			}
			if(!empty($orderDetails))
			{			
				$updateArray = array("transactionStatus" => $ResponseMessage,"transactionDetails" => serialize($input));
				DB::table('amr_cp_orders')->where('order_no', $orderId)->update($updateArray);
			}		
				
			if($ResponseMessage!='')
			{
				$emailData['hashTotal']=$hashTotal;
				$emailData['cont_person']=$cont_person;
				$emailData['address']=$address;
				$emailData['city']=$city;
				$emailData['state']=$state;
				$emailData['zcode']=$zcode;
				$emailData['country_name']=$country_name;
				$emailData['cont_no']=$cont_no;
				$emailData['email']=$email;
				$emailData['ResponseMessage']=$ResponseMessage;	
				$emailData['orderDetails']=$orderDetails;
				$emailData['paymentDoneBYStudent'] = $paymentDoneBYStudent;
				$emailData['isCampaignURL'] = $isCampaignURL;
				$emailData['campaignCode'] = $campaignCode;
				$emailData['campaignBy'] = $campaignBy;		
					//  to payments@alliedanalytics.com 
				Mail::send('mailTemplates.HDFCPaymentSuccess', $emailData, function($message) use ($orderId,$subjectLine){ 
					$message->from('purchase@alliedmarketresearch.com','Allied Market Research')
					->to('krutik.dhameliya@alliedanalytics.com','Allied Market Research Payment')								
					->subject($subjectLine." ".$orderId);
				});		
			}
			if(!empty($orderDetails)) {
				return view('paymentGateway.hdfcThanks',$pageData);
			}
			else {
				return redirect('shopping-cart');
			}			
		}		
		else {
			return redirect('shopping-cart');
		}
	}
	public function twoCoLoading($orderId,Request $request) 
	{
		if($orderId!='') {
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,true);
			if(!empty($orderDetails)) {
				$pageData['metaTitle']="Pay Using 2Checkout - AMR";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";    
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";     
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['order_no'] = $orderDetails->order_no;
				return view('paymentGateway.goto_2checkout',$pageData);
			}
			else
			{
				return redirect('shopping-cart');
			}
		} 
	}
	public function twoCoReturn(Request $request) 
	{

		$keyArray = array();
		$valueArray = array();
		$reportLicence = array();
		$hashSecretWord = 'bluewhale';		
		$hashSid = "901415349";
		$hashTotal = $request->total;
		$hashOrder = $request->order_number;
		$StringToHash = strtoupper(md5($hashSecretWord . $hashSid . $hashOrder . $hashTotal));
		$orderId = $request->orderid;		
		/* Do not remove the @ symbol from @$request->paymentfromavenue below  */
		if(@$request->paymentfromavenue=='Avenue')
		{			
			if ($StringToHash != $request->key) {
				$resultStatus = 'Failed'; 
			}
			else  { 
				$resultStatus = 'Success';
			}		
			$orderDetails=$this->CheckoutModel->getAvenueOrderDetails($orderId,false);		
			
			if(!empty($orderDetails)) 
			{
				$amountPaid=$request->total;
				$transactionDetails=$StringToHash;
				
				$checkAvenuePayment=$this->CheckoutModel->checkAvenuePayment($orderDetails,$amountPaid,$resultStatus,$request,'2checkout',$transactionDetails);
				$cartContent=unserialize($orderDetails->cartContent);			
				$pageData['metaTitle']="Thank You for Using - Avenue";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";  
				$pageData['headerUrl'] = "Become a Reseller";		
				$pageData['headerLink'] = "become-a-reseller";       
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();				
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $checkAvenuePayment['orderDetails'];											
				$pageData['cartContent'] = $cartContent;				
				$pageData['order_no'] = $orderDetails->orderNumber;
				$pageData['orderId'] = $orderId;
				$pageData['orderStatus'] = $resultStatus;
				$pageData['transactionDetails'] = $request;				
				$pageData['paymentfromavenue'] = "Yes";				
				return view('paymentGateway.2checkout_return_avenue',$pageData);				
			}
			else {
				return redirect('http://localhost:4200/pages/checkout/shopping-cart');
			}
		}
		else
		{
			$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,false);
			$result = 'Success';
			if($orderDetails->couponCode!='' && $orderDetails->couponPrice > 0)
			{
				$isExist =  DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->first();
			}
			$updateArray = array(
				"transactionStatus" => $result,
				"transactionDetails" => $StringToHash,
			);
			DB::table('amr_cp_orders')
			->where('id', $orderId)
			->update($updateArray);
			if($orderId !='') 
			{
				$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,false);
				foreach (unserialize($orderDetails->cart_contents) as $reportData) 
				{
					$reportId []= $reportData['id'];
					$reportTitles [] = $reportData['name'];
					if(!in_array($reportData['options']['license'],$reportLicence, true))
					{
						$reportLicence [] = $reportData['options']['license'];
					}
				}
				$reportTitles = implode(',',$reportTitles);
				$reportLicence = implode(',',$reportLicence);
				if(!empty($orderDetails)) 
				{
					$pageData['metaTitle']="Thank You for Using - AMR";
					$pageData['metaDescription']=""; 
					$pageData['metaKeyword']="";  
					$pageData['headerUrl'] = "Become a Reseller";		
					$pageData['headerLink'] = "become-a-reseller";       
					$pageData['getCategories']=$this->HomeModel->get_active_categories();
					$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
					$pageData['reportDetails']=$this->ReportsModel->showReportPreview($reportId);
					$pageData['getCountry']=$this->CmsFooterModel->get_countries();
					$pageData['orderDetails'] = $orderDetails;
					$cartContent=unserialize($orderDetails->cart_contents);								
					$pageData['cartContent'] = $cartContent;				
					$pageData['checkoutId'] = $orderDetails->checkout_id;
					$pageData['order_no'] = $orderDetails->order_no;
					$pageData['orderId'] = $orderId;
					$pageData['orderStatus'] = $result;
					$pageData['transactionDetails'] = $request;
					$pageData['reportTitles'] = $reportTitles;
					$pageData['reportLicence'] = $reportLicence;
					$pageData['paymentfromavenue'] = "No";
					$orderNo = $orderDetails->order_no;
					$paymentDoneBYStudent='No';
					$isCampaignURL='';
					$campaignCode='';
					$campaignBy='';
					$isAvenuePayment = false;
					if($result == 'Success')
					{
						Cart::destroy();
						$subjectLine = 'Success:: Payment Successful through 2Checkout. Order No: ';					
						/* code by krutik on 23/9/2019 */
						$fullpurchase = true;
						$chapters = '';
						/* code by krutik ends here */
						$checkStudentCampaign =  DB::table('studentsCampaign')->where('checkoutID', $orderDetails->checkout_id)->first();	
						if(!empty($checkStudentCampaign)) 
						{
							DB::table('studentsCampaign')->where('checkoutID', $orderDetails->checkout_id)->update(['ispaymentReceived' => 'Yes']);
							DB::table('amr_query_formdata')->where('query_code', $checkStudentCampaign->queryCode)->update(['amr_automail_status' => 'N']);
							/* code by krutik on 23/9/2019 */
							$paymentDoneBYStudent='Yes';
							if($checkStudentCampaign->selectedChapter != 'all')
							{ 
								$fullpurchase = false;
								$chapters = $checkStudentCampaign->selectedChapter;
								$chapters = implode(', ', str_split($chapters));
							}
							/* code by krutik ends here */
							$this->setCloseAndWon($checkStudentCampaign->queryCode);
						}
						$data = array(
							'checkoutId' => $orderDetails->checkout_id,
							'Total' => $request->total,
							'gateway' => '2Checkout'
						); 		
						/* code by krutik on 23/9/2019 */			
						$checkoutData =  DB::table('amr_checkout')->select('campaignCode', 'paymentThrough')->where('id', $orderDetails->checkout_id)->first();
						$checkCampaignCode =  $checkoutData->campaignCode;
						$paymentFrom =  $checkoutData->paymentThrough;
					
						if(empty($checkCampaignCode->campaignCode))
						{
							$this->generateInvoice($fullpurchase, $orderDetails, 1, $paymentDoneBYStudent, $paymentFrom, $chapters);	
							if($paymentFrom == 'Reportviewer')
							{
								$this->updateReportViewer($orderDetails->checkout_id, $orderNo);
							}
							/* code by krutik for new avenue page */
							$isAvenue = substr($paymentFrom, 0, 6);
							if($isAvenue == 'Avenue')
							{
								$this->createAvenueAccount($orderDetails, $paymentFrom);
								$isAvenuePayment = true;
							}
							/* code by krutik for new avenue page ends here */
						}
						else
						{
							$this->customPaymentMail($orderDetails, '2CHECKOUT'); /* Custom Payment */
						}	
						/* code by krutik ends here */				
					}
					else
					{
						$subjectLine = 'Failed:: Payment Unsuccessful through 2Checkout. Order No: ';
					}
					
					$emailData['orderDetails'] = $orderDetails;
					$emailData['Amount'] = $request->total;
					$emailData['paymentDoneBYStudent'] = $paymentDoneBYStudent;
					$emailData['isCampaignURL'] = $isCampaignURL;
					$emailData['campaignCode'] = $campaignCode;
					$emailData['campaignBy'] = $campaignBy;
					/* to:payments@alliedanalytics.com */
					Mail::send('mailTemplates.2checkoutMail', $emailData, function($message) use ($orderNo,$subjectLine)
					{ 
						$message->from('purchase@alliedmarketresearch.com','Allied Market Research')
							->to('krutik.dhameliya@alliedanalytics.com','Allied Market Research Payment')					
							->subject($subjectLine.$orderNo);	
					});
					$mysqlAVE = DB::connection('mysqlAVE');
					$ids = array(1,2,3,4);
					$packageDetail = $mysqlAVE->table('packages')->whereIn('id',$ids)->get();
					$pageData['packageDetail']=$packageDetail;
					$pageData['orderId'] =$orderId;
					if($isAvenuePayment){
						return view('paymentGateway.avenueThankYouPage',$pageData);	
					}else{
						return view('paymentGateway.avenueThankYouPage',$pageData);
					}
				}
				else
				{
					return redirect('shopping-cart');
				}
			}
			else
			{
				return redirect('shopping-cart');
			}
		}
	}
	public function paypalSuccess(Request $request)
    {
		$result = '';
        $provider = PayPal::setProvider('express_checkout');
        $recurring = false;
        $token = $request->get('token');
		$PayerID = $request->get('PayerID');
		$orderId =Session::get('orderId');
		$reportLicence = array();
		$cart = $this->getCheckoutData($recurring,$orderId);
		$orderDetails=$this->CheckoutModel->getOrderDetails($cart['order_id'],false);
		$response = $provider->getExpressCheckoutDetails($token);
		if($response['ACK'] !='')
		{
			$result = 'Success';
			if($orderDetails->couponCode!='' && $orderDetails->couponPrice > 0)
			{
				$isExist =  DB::table('coupons_master')->where('couponCode', $orderDetails->couponCode)->first();
			}
		} 
		else 
		{ 
			$result = 'Fail'; 
		}
		$actualOrderId=0;
		if($orderId !='') 
		{
			$actualOrderId=($cart['order_id']);
			$orderDetails=$this->CheckoutModel->getOrderDetails($actualOrderId,false);
			foreach (unserialize($orderDetails->cart_contents) as $reportData) 
			{
				$reportId []= $reportData['id'];
				$reportTitles [] = $reportData['name'];
				if(!in_array($reportData['options']['license'],$reportLicence, true))
				{
					$reportLicence [] = $reportData['options']['license'];
				}
			}
			$reportTitles = implode(',',$reportTitles);
			$reportLicence = implode(',',$reportLicence);
			if(!empty($orderDetails)) 
			{
				$pageData['metaTitle']="Thank You for Using - AMR";
				$pageData['metaDescription']=""; 
				$pageData['metaKeyword']="";    
				$pageData['headerUrl'] = "Become a Reseller";		
        		$pageData['headerLink'] = "become-a-reseller";     
				$pageData['getCategories']=$this->HomeModel->get_active_categories();
				$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
				$pageData['reportDetails']=$this->ReportsModel->showReportPreview($reportId);
				$pageData['getCountry']=$this->CmsFooterModel->get_countries();
				$pageData['orderDetails'] = $orderDetails;
				$cartContent=unserialize($orderDetails->cart_contents);								
				$pageData['cartContent'] = $cartContent;				
				$pageData['checkoutId'] = $orderDetails->checkout_id;
				$pageData['order_no'] = $orderDetails->order_no;
				$pageData['orderId'] = $orderId;
				$pageData['orderStatus'] = $response['ACK'];
				$pageData['transactionDetails'] = $orderDetails;
				$pageData['reportTitles'] = $reportTitles;
				$pageData['reportLicence'] = $reportLicence;
				$orderNo = $orderDetails->order_no;
				$paymentDoneBYStudent='No';
				$isCampaignURL='';
				$campaignCode='';
				$campaignBy='';
				$paymentStatus="Cancel";
				$isAvenuePayment = false;
				if (in_array(strtoupper($response['ACK']), ['SUCCESS', 'SUCCESSWITHWARNING'])) 
				{					
					$payment_status = $provider->doExpressCheckoutPayment($cart, $token, $PayerID);		
					if ($payment_status['ACK']=='Success') 
					{
						$paymentStatus='Success';
						Cart::destroy();
						$subjectLine = 'Success:: Payment Successful through PayPal. Order No: '; 
						$checkStudentCampaign =  DB::table('studentsCampaign')->where('checkoutID', $orderDetails->checkout_id)->first();
						/* code by krutik on 23/9/2019 */
						$fullpurchase = true;
						$chapters = '';
						/* code by krutik ends here */
						if(!empty($checkStudentCampaign)) 
						{
							DB::table('studentsCampaign')->where('checkoutID', $orderDetails->checkout_id)->update(['ispaymentReceived' => 'Yes']);
							DB::table('amr_query_formdata')->where('query_code', $checkStudentCampaign->queryCode)->update(['amr_automail_status' => 'N']);
							/* code by krutik on 23/9/2019 */
							$paymentDoneBYStudent='Yes';
							if($checkStudentCampaign->queryCode != 'all')
							{
								$fullpurchase = false;
								$chapters = $checkStudentCampaign->selectedChapter;
								$chapters = implode(', ', str_split($chapters));
							}
							/* code by krutik ends here */
							$this->setCloseAndWon($checkStudentCampaign->queryCode);
						}							
						$data = array(
							'checkoutId' => $orderDetails->checkout_id,
							'Total' => $response['AMT'],
							'gateway' => 'PayPal'
						); 								
						$this->salesEffortsPaymentReceived($this->paymentReceivedURL,$data);
						/* code by krutik on 23/9/2019 */
						$checkoutData =  DB::table('amr_checkout')->select('campaignCode', 'paymentThrough')->where('id', $orderDetails->checkout_id)->first();
						$checkCampaignCode =  $checkoutData->campaignCode;
						$paymentFrom =  $checkoutData->paymentThrough;
						if(empty($checkCampaignCode->campaignCode))
						{
							$this->generateInvoice($fullpurchase, $orderDetails, 2, $paymentDoneBYStudent, $paymentFrom, $chapters);
							if($paymentFrom == 'Reportviewer'){
								$this->updateReportViewer($orderDetails->checkout_id, $orderNo);
							}
						}
						else
						{
							$this->customPaymentMail($orderDetails, 'PAYPAL'); // Custom Payment
						}
						/* code by krutik ends here */
						$updateArray = array(
							"transactionStatus" => $payment_status['ACK'],
							"transactionDetails" => serialize($response),
						);
						DB::table('amr_cp_orders')
						->where('order_no', $orderId)
						->update($updateArray);
					} 
					else 
					{
						$subjectLine = 'Failed:: Payment Unsuccessful through PayPal. Order No: ';
					}
				}
				$orderDetails=$this->CheckoutModel->getOrderDetails($actualOrderId,false);
				$emailData['orderDetails'] = $orderDetails;
				$emailData['totalAmt'] = $response['AMT'];				 				
				$emailData['paymentDoneBYStudent'] = $paymentDoneBYStudent;	
				$emailData['isCampaignURL'] = $isCampaignURL;
				$emailData['campaignCode'] = $campaignCode;
				$emailData['campaignBy'] = $campaignBy;
				$emailData['paymentStatus'] = $paymentStatus;		
				// to : 	payments@alliedanalytics.com	
				Mail::send('mailTemplates.paypalMail', $emailData, function($message) use ($orderNo,$subjectLine){ 
					$message->from('purchase@alliedmarketresearch.com','Allied Market Research')
					->to('krutik.dhameliya@alliedanalytics.com','Allied Market Research Payment')											
					->subject($subjectLine.$orderNo);	
				});
				$mysqlAVE = DB::connection('mysqlAVE');
				$ids = array(1,2,3,4);
				$packageDetail = $mysqlAVE->table('packages')->whereIn('id',$ids)->get();
				$pageData['packageDetail']=$packageDetail;
				$pageData['orderId'] =$orderDetails->id;
				if($isAvenuePayment){
					return view('paymentGateway.avenueThankYouPage',$pageData);	
				}else{
					return view('paymentGateway.paypal_return_new',$pageData);
				}
			}
			else
			{
				return redirect('shopping-cart');
			}
		}
		else
		{
			return redirect('shopping-cart');
		}
	}
	
	/* code by krutik for new avenue page */
	public function createAvenueAccount($orderDetails, $paymentFrom)
	{
		$mysqlCon = DB::connection('mysqlAVE');

		$isUserPresent = $mysqlCon->table('users')->where('email', $orderDetails->email)->first();

		$reportData = unserialize($orderDetails->cart_contents);
		
		if($isUserPresent == null){
			
			
			$id = explode(' ', $paymentFrom);
			$packageDetail = $mysqlCon->table('packages')->where('id',@$id[1])->first();
			$dateTime = date('Y-m-d H:i:s');
			$endTime = date('Y-m-d H:i:s', strtotime('+1 years'));

			$clientInsert = array(
				'clientName' => $orderDetails->company,
				'created_at' => $dateTime,
				'updated_at' => $dateTime
			);
			$clientID = $mysqlCon->table('client_master')->insertGetId($clientInsert);

			$userInsertData = array(
				'name' => $orderDetails->full_name,
				'email' => $orderDetails->email,
				'companyname' => $orderDetails->company,
				'password' => bcrypt('avenue@123'),
				'role' => 'Admin',
				'clientId' => $clientID,
				'created_at' => $dateTime,
				'updated_at' => $dateTime
			);
			$userID = $mysqlCon->table('users')->insertGetId($userInsertData);

			$userSubInsertData = array(
				'user_id' => $userID,
				'package_id' => $packageDetail->id,
				'subscription_start_timestamp' => $dateTime,
				'subscription_end_timestamp' => $endTime,
				'expiry_protection_period' => $packageDetail->expiry_protection_period,
				'clientId' => $clientID,
				'created_at' => $dateTime,
				'updated_at' => $dateTime,
				'subscriptionFrom' => 'AMR'
			);
			$userSubID = $mysqlCon->table('user_subscriptions')->insertGetId($userSubInsertData);

			$userUpdateData = array(
				'userCode' => 'AVE' . date('Y') . $userID,
				'password' => bcrypt('AVE' . date('Y') . $userID),
				'currentSubscriptionId' => $userSubID
			);
			$result = $mysqlCon->table('users')
			->where('id', $userID)
			->update($userUpdateData);

			$packageDetail = $mysqlCon->table('packages')->where('id',$packageDetail->id)->first();
			$userSubDetailInsertData = array(
				'subscription_id' => $userSubID,
				'published_content_e_access' => $packageDetail->published_content_e_access,
				'company_profiles_e_access' => $packageDetail->company_profiles_e_access,
				'newly_added_content_e_access' => $packageDetail->newly_added_content_e_access,
				'report_updates_e_access' => $packageDetail->report_updates_e_access,
				'email_support' => $packageDetail->email_support,
				'phone_support' => $packageDetail->phone_support,
				'dashboard_history' => $packageDetail->dashboard_history,
				'customization_request' => $packageDetail->customization_request,
				'new_report_suggestion' => $packageDetail->new_report_suggestion,
				'user_min_limit' => $packageDetail->user_min_limit,
				'user_max_limit' => $packageDetail->user_max_limit,
				'analyst_meeting_credit' => $packageDetail->analyst_meeting_credit,
				'on_demand_report_credit' => $packageDetail->on_demand_report_credit,
				'pdf_downloads_credit' => $packageDetail->pdf_downloads_credit,
				'report_updates_credit' => $packageDetail->report_updates_credit,
				'data_pack_downloads_credit' => $packageDetail->data_pack_downloads_credit,
				'company_profiles_downloads_credit' => $packageDetail->company_profiles_downloads_credit,
				'original_price_per_year' => $orderDetails->subTotal,
				'discount_price' => $orderDetails->couponPrice,
				'price_per_year' => $orderDetails->grandTotal,
				'created_at' => $dateTime,
				'updated_at' => $dateTime
			);
			$mysqlCon->table('user_subscription_details')->insert($userSubDetailInsertData);
			
			$packageCreditBoosterDetail = $mysqlCon->table('packages_credit_booster_prices')->where('package_id',$packageDetail->id)->first();
			$subscription_credit_booster_prices = array(
				'subscription_id' => $userSubID,
				'analyst_meeting_credit_booster_per_unit_price' => $packageCreditBoosterDetail->analyst_meeting_credit_booster_per_unit_price,
				'on_demand_report_credit_booster_per_unit_price' => $packageCreditBoosterDetail->on_demand_report_credit_booster_per_unit_price,
				'pdf_downloads_credit_booster_per_unit_price' => $packageCreditBoosterDetail->pdf_downloads_credit_booster_per_unit_price,
				'report_updates_credit_booster_per_unit_price' => $packageCreditBoosterDetail->report_updates_credit_booster_per_unit_price,
				'data_pack_downloads_credit_booster_per_unit_price' => $packageCreditBoosterDetail->data_pack_downloads_credit_booster_per_unit_price,
				'company_profiles_downloads_credit_booster_per_unit_price' => $packageCreditBoosterDetail->company_profiles_downloads_credit_booster_per_unit_price,
				'created_at' => $dateTime,
				'updated_at' => $dateTime
			);
			$mysqlCon->table('subscription_credit_booster_prices')->insert($subscription_credit_booster_prices);
			
			$packageCreditBulkBoosterDetail = $mysqlCon->table('packages_credit_bulk_booster_prices')->where('package_id',$packageDetail->id)->first();
			$subscription_credit_bulk_booster_prices = array(
				'subscription_id' => $userSubID,
				'up_to_twenty_five_bulk_credit_rate' => $packageCreditBulkBoosterDetail->up_to_twenty_five_bulk_credit_rate,
				'up_to_hundred_bulk_credit_rate' => $packageCreditBulkBoosterDetail->up_to_hundred_bulk_bulk_credit_rate,
				'up_to_two_hundred_fiftieth_bulk_credit_rate' => $packageCreditBulkBoosterDetail->up_to_two_hundred_fiftieth_bulk_credit_rate,
				'more_then_two_hundred_fiftieth_bulk_credit_rate' => $packageCreditBulkBoosterDetail->more_then_two_hundred_fiftieth_bulk_credit_rate,
				'created_at' => $dateTime,
				'updated_at' => $dateTime
			);
			$mysqlCon->table('subscription_credit_bulk_booster_prices')->insert($subscription_credit_bulk_booster_prices);
			
			$catIds = DB::table('categories')->select('cat_id')->where('cat_status','Active')->get();
			
			$batch = array();
			foreach($catIds as $id)
			{
				array_push($batch,array(
					'subscription_id' => $userSubID,
					'category_id' => $id->cat_id
				));
			}
			$mysqlCon->table('subscription_report_categories')->insert($batch);

			$full_name = $orderDetails->full_name;
			$email = $orderDetails->email;
			$sendData['full_name'] = $full_name;
			$sendData['email'] = $email;
			$sendData['password'] = 'AVE' . date('Y') . $userID;
			$sendData['title'] = $reportData[0]['name'];
			$mailView = 'mailTemplates.avenueAccountCreated';
			$subjectLine = 'Login Credentials';
			$fromEmail = 'noreply@alliedmarketresearch.com';
			Mail::send($mailView, $sendData, function($message) use ($email,$full_name,$subjectLine,$fromEmail) { 
				$message->from($fromEmail,'Allied Market Research')
					->to($email,$full_name)	
					->bcc('krutik.dhameliya@alliedanalytics.com','Sushant Nerkar')				
					->subject($subjectLine);
			});
		}
		else
		{
			$phone = explode(' ',$orderDetails->phone);
			$full_name = $orderDetails->full_name;
			$email = $orderDetails->email;
			$subjectLine = 'Avenue User Purchased Subscription Again.';
			$mailView = 'mailTemplates.avenueAccountNotCreated';
			$sendData['full_name'] = $full_name;
			$sendData['email'] = $email;
			$sendData['phone'] = $phone[1];
			$sendData['title'] = $reportData[0]['name'];
			$fromEmail = 'noreply@alliedmarketresearch.com';
			Mail::send($mailView, $sendData, function($message) use ($email,$full_name,$subjectLine,$fromEmail) { 
				$message->from($fromEmail,'Allied Market Research')
					->to($email,$full_name)	
					->bcc('krutik.dhameliya@alliedanalytics.com','Sushant Nerkar')							
					->subject($subjectLine);
			});
		}
	}
	/* code by krutik for new avenue page ends here */



	/* code by krutik on 23/9/2019 */
	/*public function isOnlineOnly($fullpurchase, $reportData, $paymentDoneBYStudent, $orderDetails)
	{
		$mysqlCon = \DB::connection('mysql5');
		$phone = explode(' ',$orderDetails->phone);
		$paid = (int)$reportData['price'] + (int)$orderDetails->internetHandling;
		if($paymentDoneBYStudent == 'Yes')
		{
			$daysDetail = DB::table('countries_currency')      
					->where('country_name',$orderDetails->country)   
					->first();
			$daysDetail	= (array)$daysDetail;
			$orderDetails->grandTotal = 100;
			$days = array_search($orderDetails->grandTotal, $daysDetail);
			if($days){
				$validity_days = [
					'full_report_price' => 10,
					'one_day_price' => 1,
					'five_day_price' => 5,
					'seven_day_price' => 7,
					'ten_day_price' => 10,
				];
				
				$validity = $validity_days[$days];
			}
			$days = $validity;
			$hours = $days * 24;
			$userType = 'Student';
		}
		else
		{
			$userType = 'Corporate';
			$hours = 1080;
			$days = 45;
		}
		$whereCondition = array(
			'rep_upcoming_published_status' => 'P',
			'rep_status' => 'Y',
			'rep_id'=> $reportData['id'],
		);
		$requiredReportData = DB::table('bmr_report')  
				->leftJoin('bmr_sub_cat_1', 'bmr_report.rep_sub_cat_1_id', '=', 'bmr_sub_cat_1.sc1_id')    
				->where($whereCondition)   
				->select('*')
				->first();
		$s3Client = S3Client::factory(array(
			'credentials' => array(
				'key'    => 'AKIAIVOS6IOGILFWINKQ',
				'secret' => 'wqeFaX+ubrkl7tqhFy3Xd007tZ6cYZldtCSwxkaY',
			)
		));
		$bucketName = 'alliedstore';
		$report_file_name =  str_replace(' ', '_', $requiredReportData->rep_report_code).'.pdf'; 
		$cat_name1 = str_replace(" ","_", $requiredReportData->sc1_name);
		$cat_name2 = str_replace("amp;","", $cat_name1);
		$cat_name = str_replace("&","and", $cat_name2);  
		$response = $s3Client->doesObjectExist($bucketName, 'Reports_Store/'.$cat_name.'/'.$report_file_name);

		$sendData2['report_title'] = $reportData['name'];
		$sendData2['reportCode'] = $reportData['options']['reportCode'];
		$sendData2['amount'] = $paid;
		$sendData2['full_name'] = $orderDetails->full_name;
		$sendData2['phone'] = $orderDetails->phone;
		$sendData2['email'] = $orderDetails->email;
		$sendData2['company'] = $orderDetails->company;
		$sendData2['job_role'] = $orderDetails->job_role;
		$sendData2['address'] = $orderDetails->address;
		$sendData2['country'] = $orderDetails->country;
		$sendData2['state'] = $orderDetails->state;
		$sendData2['city'] = $orderDetails->city;
		$sendData2['postalCode'] = $orderDetails->zip_postal;
		$sendData2['status'] = false;
		$orderNo = $orderDetails->order_no;
		$sendData2['fullpurchase'] = $fullpurchase;
		if($reportData['options']['license'] == 'Online Only' && $fullpurchase && $response)
		{
			$expDateTime = date("Y-m-d H:i:s", strtotime("+{$hours} hours"));
			$insertdata = array(
				'full_name' => $orderDetails->full_name,
				'company_profile' => $orderDetails->company,
				'job_role' => $orderDetails->job_role,
				'email_id' => $orderDetails->email,
				'mobile_number' => $phone[1],
				'password' => ' ',
				'expire_datetime' => $expDateTime,
				'access_hours' => $hours,
				'report_title' => $reportData['name'],
				'report_id' => $reportData['id'],
				'pdf_path' => ' ',
				'add_date' => date('Y-m-d H:i:s'),
				'status' => '1',
				's3' => 1,
				'user_type' => $userType,
				'paid' => $paid
			);
			$insertID = $mysqlCon->table('user_master')->insertGetId($insertdata);
			$name = str_replace(' ', '', $orderDetails->full_name);
			$password = $name . '@' . rand(100000,1000000);
			$result = $mysqlCon->table('user_master')
						->where('id', $insertID)
						->update(['password' => $password]);
			$sendData['report_title'] = $reportData['name'];
			$sendData['full_name'] = $orderDetails->full_name;
			$sendData['days'] = $days;
			$sendData['hours'] = $hours;
			$sendData['email'] = $orderDetails->email;
			$sendData['password'] = $password;
			$email = $orderDetails->email;
			$full_name = $orderDetails->full_name;
			$subjectLine = 'Login Credentials';
			Mail::send('mailTemplates.credentialMail',$sendData, function($message) use ($email,$full_name,$subjectLine)
			{ 
				$message->from('dispatch@alliedmarketresearch.com','Allied Market Research')
					->to($email,$full_name)		
					->subject($subjectLine);
			});
			$sendData2['status'] = true;
			$subjectLine = 'ReportViewer Credentials Dispatched Successfully for Order no: ';
			Mail::send('mailTemplates.dispatchStatusMail',$sendData2, function($message) use ($orderNo, $subjectLine)
			{ 
				$message->from('noreply@alliedmarketresearch.com','Allied Market Research')
					->to('krutik.dhameliya@alliedanalytics.com','Allied Market Research Payment')	
					->subject($subjectLine.$orderNo);
			});
			return 'Yes';
		}
		$subjectLine = 'ReportViewer Credentials Not Dispatched for Order no: ';
		Mail::send('mailTemplates.dispatchStatusMail',$sendData2, function($message) use ($orderNo, $subjectLine)
		{ 
			$message->from('noreply@alliedmarketresearch.com','Allied Market Research')
				->to('krutik.dhameliya@alliedanalytics.com','Allied Market Research Payment')		
				->subject($subjectLine.$orderNo);
		});
		return 'No';
	}*/

	public function isOnlineOnly($fullpurchase, $reportData, $paymentDoneBYStudent, $orderDetails, $chapters)
	{
		$mysqlCon = DB::connection('mysql5');
		$phone = explode(' ',$orderDetails->phone);
		$paid = (float)$reportData['price'] + (float)$orderDetails->internetHandling;
		if($paymentDoneBYStudent == 'Yes')
		{		
			$checkCountryWise="Yes";
			$checkCustomAccess = DB::table('amr_checkout')			
			->where("id",$orderDetails->checkout_id)   
			->select('customDaysAccess')
			->first();
			if(!empty($checkCustomAccess))
			{
				$accessHRS=intval(@$checkCustomAccess->customDaysAccess);
				if($accessHRS>0)
				{
					$days = $accessHRS;
					$hours = $days * 24;
					$checkCountryWise="No";
				}
			}	
			if($checkCountryWise=='Yes')
			{	
				$days = DB::table('countries_currency')      
					->where('country_name',$orderDetails->country)   
					->select('validity')
					->first();
					$days = (int)$days->validity;
					$hours = $days * 24;
			}			
			$userType = 'Student';
		}
		else
		{
			$hours = 1080;
			$days = 45;
			$checkCustomAccess = DB::table('amr_checkout')			
			->where("id",$orderDetails->checkout_id)   
			->select('customDaysAccess')
			->first();
			if(!empty($checkCustomAccess) && intval(@$checkCustomAccess->customDaysAccess)>0) 
			{
				$accessHRS=intval(@$checkCustomAccess->customDaysAccess);
				if($accessHRS>0)
				{
					$days = $accessHRS;
					$hours = $days * 24;					
				}
			}
			$userType = 'Corporate';	
		}
		$whereCondition = array(
			'rep_upcoming_published_status' => 'P',
			'rep_status' => 'Y',
			'rep_id'=> $reportData['id'],
		);
		$requiredReportData = DB::table('bmr_report')  
				->leftJoin('bmr_sub_cat_1', 'bmr_report.rep_sub_cat_1_id', '=', 'bmr_sub_cat_1.sc1_id')    
				->where($whereCondition)   
				->select('*')
				->first();
		/* check if report(PDF) present in s3 bucket */
		$s3Client = S3Client::factory(array(
			'credentials' => array(
				'key'    => 'AKIAIVOS6IOGILFWINKQ',
				'secret' => 'wqeFaX+ubrkl7tqhFy3Xd007tZ6cYZldtCSwxkaY',
			)
		));
		$bucketName = 'alliedstore';
		$report_file_name =  str_replace(' ', '_', $requiredReportData->rep_report_code).'.pdf'; 

		$response = $s3Client->doesObjectExist($bucketName, 'Reports_Store/ReportsPDF/'.$report_file_name);

		/* dispatch success/fail email details */
		$sendData2['report_title'] = $reportData['name'];
		$sendData2['reportCode'] = $reportData['options']['reportCode'];
		$sendData2['amount'] = $paid;
		$sendData2['full_name'] = $orderDetails->full_name;
		$sendData2['phone'] = $orderDetails->phone;
		$sendData2['email'] = $orderDetails->email;
		$sendData2['company'] = $orderDetails->company;
		$sendData2['job_role'] = $orderDetails->job_role;
		$sendData2['address'] = $orderDetails->address;
		$sendData2['country'] = $orderDetails->country;
		$sendData2['state'] = $orderDetails->state;
		$sendData2['city'] = $orderDetails->city;
		$sendData2['postalCode'] = $orderDetails->zip_postal;
		$sendData2['status'] = false;
		/* 6/1/20 */
		$sendData2['hours'] = $hours;
		$sendData2['userType'] = $userType;
		$sendData2['chapters'] = $chapters;
		/* 6/1/20 */
		$orderNo = $orderDetails->order_no;
		$sendData2['fullpurchase'] = $fullpurchase;
		if($reportData['options']['license'] == 'Online Only' && $fullpurchase && $response)
		{
			$aveCon = DB::connection('mysqlAVE');
			$checkAlready = $aveCon->table('users')
			->select('*')
			->where('role','=','Report Viewer')
			->where('email','=',$orderDetails->email)
			->first();
			if(!empty($checkAlready))
			{
				$expDateTime = date("Y-m-d H:i:s", strtotime("+{$hours} hours"));
				$subscriptionUserId = $checkAlready->id;
				$insertdata = array(
					'UserId' => $checkAlready->id,
					'pdf_path' => ' ',
					'access_hours' => $hours,
					'paid' => $paid,
					'reportviewerManager' => ' ',
					'report_title' => get_shortTitle($reportData['name']),
					'report_id' => $reportData['id'],
					'add_date' => date('Y-m-d H:i:s'),
					's3' => 1,
					'status' => '1',
					'expire_datetime' => $expDateTime,
					'isGeneratedBy' => 'System Generated',
				);
				$insertID = $aveCon->table('reportviewerDetails')->insertGetId($insertdata);
			}
			else{
				$name = str_replace(' ', '', $orderDetails->full_name);
				$password = $name . '@' . rand(100000,1000000);
				$insertUserData = array(
					'name' => $orderDetails->full_name,
					'password' => bcrypt($password),
					'reportviewerpassword' => $password,
					'email' => $orderDetails->email,
					'companyname' => $orderDetails->company,
					'reportViewerRole' => $userType,
					'role' => 'Report Viewer',
					'created_at' => date('Y-m-d H:i:s'),
				);
				$insertID = $aveCon->table('users')->insertGetId($insertUserData);

				$insertUserDetails = array(
					'userId' => $insertID,
					'firstName' => $orderDetails->full_name,
					'lastName' => 'NA',
					'userProfile' => 'userprofileimage/male.jpg',
					'dept' => 'NA',
					'jobRole' => $orderDetails->job_role,
					'countryName' => $orderDetails->country,
					'stateName' => $orderDetails->state,
					'cityName' => $orderDetails->city,
					'zipCode' => $orderDetails->zip_postal,
					'companyName' => $orderDetails->company,
					'address' => $orderDetails->address,
					'phone' => $orderDetails->phone,
					'email' => $orderDetails->email
				);
				
				$insertUserDetailsID = $aveCon->table('UserDetails')->insertGetId($insertUserDetails);

				$expDateTime = date("Y-m-d H:i:s", strtotime("+{$hours} hours"));
				$subscriptionUserId = $insertID;
				$insertdata = array(
					'UserId' => $insertID,
					'pdf_path' => ' ',
					'access_hours' => $hours,
					'paid' => $paid,
					'reportviewerManager' => ' ',
					'report_title' => get_shortTitle($reportData['name']),
					'report_id' => $reportData['id'],
					'add_date' => date('Y-m-d H:i:s'),
					's3' => 1,
					'status' => '1',
					'expire_datetime' => $expDateTime,
					'isGeneratedBy' => 'System Generated',
				);
				$insertID = $aveCon->table('reportviewerDetails')->insertGetId($insertdata);

				$insertClientID = array('clientName'=>$orderDetails->email,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'));

				$clientCheckId = $aveCon->table('client_master')
				->select('clientId')
				->where('clientName','=',$orderDetails->email)
				->first();
				if(!empty($clientCheckId)) {
					$clientMasterId = $clientCheckId->clientId;
				}
				else {
					$clientMasterId = $aveCon->table('client_master')->insertGetId($insertClientID);
				}
				$userSubInsertData = array(
				'user_id' => $subscriptionUserId,
				'package_id' => 1,
				'clientId'=>$clientMasterId,
				'subscription_start_timestamp' => date('Y-m-d H:i:s'),
				'subscription_end_timestamp' =>$expDateTime,
				'expiry_protection_period' => 0,
				'package_type' => 'custom',
				'created_at' => date('Y-m-d H:i:s'),
				'updated_at' => date('Y-m-d H:i:s')
				);
				$subscriptionId = $aveCon->table('user_subscriptions')->insertGetId($userSubInsertData);

				if($subscriptionId>0)
				{	
					$allCategories = DB::table('categories')->get();
					if(count($allCategories)>0) {
							foreach($allCategories as $allCategorieslst) {
							$insertDataCat=array("subscription_id"=>$subscriptionId,"category_id"=>$allCategorieslst->cat_id);	
							$aveCon->table('subscription_report_categories')->insertGetId($insertDataCat);
							$aveCon->table('subscription_company_profile_categories')->insertGetId($insertDataCat);
						}
					}	
					$aveCon->table('subscription_paid_user')->insert(
						['subscription_id' => $subscriptionId, 'user_count' => 0]
						);
					$userUpdateData = array(
					'userCode' => 'AVE' . date('Y') . $subscriptionUserId,
					'currentSubscriptionId' => $subscriptionId,
					'clientId'=>$clientMasterId
					);
					$result = $aveCon->table('users')
					->where('id', $subscriptionUserId)
					->update($userUpdateData);
				
					$packageDetail = $aveCon->table('packages')->where('id',1)->first();
					$userSubDetailInsertData = array(
					'subscription_id' => $subscriptionId,
					'published_content_e_access' => $packageDetail->published_content_e_access,
					'company_profiles_e_access' => $packageDetail->company_profiles_e_access,
					'newly_added_content_e_access' => $packageDetail->newly_added_content_e_access,
					'report_updates_e_access' => $packageDetail->report_updates_e_access,
					'email_support' => $packageDetail->email_support,
					'phone_support' => $packageDetail->phone_support,
					'dashboard_history' => $packageDetail->dashboard_history,
					'customization_request' => $packageDetail->customization_request,
					'new_report_suggestion' => $packageDetail->new_report_suggestion,
					'user_min_limit' => 0,
					'user_max_limit' => 0,
					'analyst_meeting_credit' => 0,
					'on_demand_report_credit' => 0,
					'pdf_downloads_credit' => 0,
					'report_updates_credit' => 0,
					'data_pack_downloads_credit' => 0,
					'company_profiles_downloads_credit' => 0,
					'original_price_per_year' => $packageDetail->original_price_per_year,
					'discount_price' => $packageDetail->discount_price,
					'price_per_year' => $packageDetail->price_per_year,
					'created_at' => date('Y-m-d H:i:s'),
					'updated_at' => date('Y-m-d H:i:s')
					);
					$aveCon->table('user_subscription_details')->insert($userSubDetailInsertData);
					
					$packageCreditBoosterDetail = $aveCon->table('packages_credit_booster_prices')->where('package_id',1)->first();
					$subscription_credit_booster_prices = array(
					'subscription_id' => $subscriptionId,
					'analyst_meeting_credit_booster_per_unit_price' => $packageCreditBoosterDetail->analyst_meeting_credit_booster_per_unit_price,
					'on_demand_report_credit_booster_per_unit_price' => $packageCreditBoosterDetail->on_demand_report_credit_booster_per_unit_price,
					'pdf_downloads_credit_booster_per_unit_price' => $packageCreditBoosterDetail->pdf_downloads_credit_booster_per_unit_price,
					'report_updates_credit_booster_per_unit_price' => $packageCreditBoosterDetail->report_updates_credit_booster_per_unit_price,
					'data_pack_downloads_credit_booster_per_unit_price' => $packageCreditBoosterDetail->data_pack_downloads_credit_booster_per_unit_price,
					'company_profiles_downloads_credit_booster_per_unit_price' => $packageCreditBoosterDetail->company_profiles_downloads_credit_booster_per_unit_price,
					'created_at' => date('Y-m-d H:i:s'),
					'updated_at' => date('Y-m-d H:i:s')
					);
					$aveCon->table('subscription_credit_booster_prices')->insert($subscription_credit_booster_prices);
					
					$packageCreditBulkBoosterDetail = $aveCon->table('packages_credit_bulk_booster_prices')->where('package_id',1)->first();
					$subscription_credit_bulk_booster_prices = array(
					'subscription_id' => $subscriptionId,
					'up_to_twenty_five_bulk_credit_rate' => $packageCreditBulkBoosterDetail->up_to_twenty_five_bulk_credit_rate,
					'up_to_hundred_bulk_credit_rate' => $packageCreditBulkBoosterDetail->up_to_hundred_bulk_bulk_credit_rate,
					'up_to_two_hundred_fiftieth_bulk_credit_rate' => $packageCreditBulkBoosterDetail->up_to_two_hundred_fiftieth_bulk_credit_rate,
					'more_then_two_hundred_fiftieth_bulk_credit_rate' => $packageCreditBulkBoosterDetail->more_then_two_hundred_fiftieth_bulk_credit_rate,
					);
					$aveCon->table('subscription_credit_bulk_booster_prices')->insert($subscription_credit_bulk_booster_prices);
				}			
				
				$sendData['report_title'] = $reportData['name'];
				$sendData['full_name'] = $orderDetails->full_name;
				$sendData['days'] = $days;
				$sendData['hours'] = $hours;
				$sendData['email'] = $orderDetails->email;
				$sendData['password'] = $password;
				$email = $orderDetails->email;
				$full_name = $orderDetails->full_name;
				$subjectLine = 'Login Credentials';
				Mail::send('mailTemplates.credentialMail',$sendData, function($message) use ($email,$full_name,$subjectLine)
				{ 
					$message->from('dispatch@alliedmarketresearch.com','Allied Market Research')
						->to($email,$full_name)		
						// ->cc('dispatch@alliedmarketresearch.com','Allied Market Research')
						->cc('sushant.nerkar@alliedanalytics.com')
						->bcc('krutik.dhameliya@alliedanalytics.com','Sushant Nerkar')
						->subject($subjectLine);
				});
				$sendData2['status'] = true;
				
				
				$subjectLine = 'ReportViewer Credentials Dispatched Successfully for Order no: ';
				Mail::send('mailTemplates.dispatchStatusMail',$sendData2, function($message) use ($orderNo, $subjectLine)
				{ 
					$message->from('noreply@alliedmarketresearch.com','Allied Market Research')
						->to('sushant.nerkar@alliedanalytics.com','Vinod Gadekar')
						->cc('krutik.dhameliya@alliedanalytics.com','Krutik Dhameliya')
						->bcc('krutik.dhameliya@alliedanalytics.com','Sushant Nerkar')
						->subject($subjectLine.$orderNo);
				});
				return 'Yes';
			}
		}	
		else{
			$subjectLine = 'ReportViewer Credentials Not Dispatched for Order no: ';
			Mail::send('mailTemplates.dispatchStatusMail',$sendData2, function($message) use ($orderNo, $subjectLine)
			{ 
				$message->from('noreply@alliedmarketresearch.com','Allied Market Research')
					->to('krutik.dhameliya@alliedanalytics.com','Krutik Dhameliya')
					->cc('sushant.nerkar@alliedanalytics.com','Vinod Gadekar')
					->bcc('krutik.dhameliya@alliedanalytics.com','Sushant Nerkar')		
					->subject($subjectLine.$orderNo);
			});
			return 'No';
		}	
	}
	public function updateReportViewer($checkoutId, $orderno)
	{
		$mysqlCon = \DB::connection('mysqlAVE');
		$result = $mysqlCon->table('reportViewer_checkout')
							->where('checkout_id', $checkoutId)
							->update(['paymentComplete' => 'Yes']);
		$checkoutType = $mysqlCon->table('reportViewer_checkout')
					->select('checkoutType', 'user_id', 'hours', 'report_id')
					->where('checkout_id',"=",$checkoutId)
					->first();
		if($checkoutType->checkoutType == 'Extend')
		{
			$userData = $mysqlCon->table('reportviewerDetails')
						->select('access_hours','expire_datetime')
						->where('UserId',"=",$checkoutType->user_id)
						->where('report_id',"=",$checkoutType->report_id)
						->first();
			if($userData->expire_datetime > date('Y-m-d H:i:s'))
			{
				$now = new DateTime($userData->expire_datetime); 
				$accessHours = (int)$userData->access_hours + (int)$checkoutType->hours;
			}
			else
			{
				$now = new DateTime(); 
				$accessHours = (int)$checkoutType->hours;
			}
			$now->add(new \DateInterval("PT{$checkoutType->hours}H"));
			$new_time = $now->format('Y-m-d H:i:s');
			$updateData = array(
				'expire_datetime' => $new_time,
				'access_hours' => $accessHours
			);
			$result = $mysqlCon->table('reportviewerDetails')
			->where('UserId',"=",$checkoutType->user_id)
			->where('report_id',"=",$checkoutType->report_id)
			->update($updateData);
		}
		else
		{
			$this->sendUpgradeMail($checkoutId, $checkoutType->user_id, $orderno);
		}
	}
	public function sendUpgradeMail($checkoutId, $userId, $orderno)
	{
		$mysqlCon = \DB::connection('mysqlAVE');
		$userData = $mysqlCon->table('reportviewerDetails')
					->Join('users', 'reportviewerDetails.UserId', '=', 'users.id')
					->select('reportviewerDetails.report_id','reportviewerDetails.mobile_number','users.name','users.email')
					->where('id',"=",$userId)
					->first();
		$subjectLine = 'SUCCESS:: Subscription Upgrade. Order No: ';
		$reportData = DB::table('bmr_report')->select('rep_title', 'rep_report_code')->where('rep_id', $userData->report_id)->first();
		$emailData['full_name'] = $userData->name;
		$emailData['email_id'] = $userData->email;
		$emailData['mobile_number'] = $userData->mobile_number;
		$emailData['report_code'] = $reportData->rep_report_code;
		$emailData['report_Title'] = $reportData->rep_title;
		Mail::send('mailTemplates.upgradeMail', $emailData, function($message) use ($orderno,$subjectLine)
		{ 
			$message->from('purchase@alliedmarketresearch.com','Allied Market Research')
				->to('sushant.nerkar@alliedanalytics.com','Allied Market Research Payment')					
				->subject($subjectLine.$orderno);	
		});
	}
	/*
	*		paymentGatewayId :
	*		"2checkout" => 1
	*		"paypal" => 2
	*		"hdfc" => 3
	*		"ccavenue" => 4
	*/
	
	public function generateInvoice($fullpurchase, $orderDetails, $paymentGatewayId, $paymentDoneBYStudent, $paymentFrom, $chapters)
	{
		
		if($paymentGatewayId == 1)
		{
			$paymentThrough = "2CHECKOUT";
		}
		elseif($paymentGatewayId == 2)
		{
			$paymentThrough = "PAYPAL";
		}
		elseif($paymentGatewayId == 3)
		{
			$paymentThrough = "HDFC";
		}
		elseif($paymentGatewayId == 4)
		{
			$paymentThrough = "CCAVENUE";
		}
		$phone = explode(' ',$orderDetails->phone);
		$query_code = 'AMRDP' . date_timestamp_get(new DateTime());
		$invoicefinal_data = array(
			'invoiceNumber' => ' ',
			'queryCode'	=> $query_code,
			'userId' => 25,
			'managerUsedId' => null,
			'activitiesId' => 8,
			'invoiceTypeId' => 2,
			'proformaInvoiceId' => 0,
			'paymentModeId' => 1,
			'bankId' => 1,
			'paymentGatewayId' => $paymentGatewayId,
			'date' => date("Y-m-d"),
			'leadSource' => 49,
			'licenseType' => 3,
			'typeOfSale' => 1,
			'organizationName' => $orderDetails->company,
			'userName' => $orderDetails->full_name,
			'email' => $orderDetails->email,
			'jobRole' => $orderDetails->job_role,
			'addressLine1' => $orderDetails->address,
			'addressLine2' => ' ',
			'country' => $orderDetails->country,
			'state' => $orderDetails->state,
			'city' => $orderDetails->city,	
			'postalCode' => $orderDetails->zip_postal,
			'telephone' => $phone[1],
			'organizationName_billing' => $orderDetails->company,
			'userName_billing' => $orderDetails->full_name,
			'email_billing' => $orderDetails->email,
			'jobRole_billing' => $orderDetails->job_role,
			'addressLine1_billing' => $orderDetails->address,
			'addressLine2_billing' => ' ',
			'country_billing' => $orderDetails->country,
			'state_billing' => $orderDetails->state,
			'city_billing' => $orderDetails->city,
			'postalCode_billing' => $orderDetails->zip_postal,
			'telephone_billing' => $phone[1],
			'reportTitle' => ' ',
			'reportCode' => ' ',
			'reportCategory' => ' ',
			'reportSubcategory' => ' ',
			'orginalPrice' => $orderDetails->subTotal,
			'discountPrice' => $orderDetails->couponPrice,
			'subTotal' => $orderDetails->cartTotal,
			'GST' => $orderDetails->gstAmount,
			'grossPrice' => floatval($orderDetails->subTotal) + floatval($orderDetails->gstAmount),
			'internetHandlingFee' => $orderDetails->internetHandling,
			'dataPack_amt' => $orderDetails->datapackAmount,
			'totalGrand' => $orderDetails->grandTotal,
			'amrshare' => null,
			'partnershare' => null,
			'invoiceStatusId' => 2,
			'dispatchStatusId' => 0,
			'researchStatusId' => 0,
			'accountsStatusId' => 2,
			'clientNote' => ' ',
			'dispatchNote' => ' ',
			'accountNote' => ' ',
			'salesNote' => ' ',
			'checkOutId' => $orderDetails->checkout_id,
			'xlsformat' => 1,
			'pdf' => 1,
			'cdrom' => 0,
			'hardcopy' => 0,
			'onlineonly' => ' ',
			'isDeleted' => 1,
			'reportId' => ' ',
			'doumentType' => 'Invoice',
			'siteName' => 'Allied Market Research',
			'subscriptionType' => 0,
			'dispatchToMailId' => $orderDetails->email,
			'dispatchCcMailId' => ' ',
			'dispatchMessege' => ' ',
			'dispatchFilePath' => ' ',
			'dispatchDate' => ' ',
			'dispatchTime' => ' ',
			'paymentDate' => null,
			'dispatchDateStatus' => 0,
			'sharedWithDispatchTeam' => 0,
			'shareReportWithClient' => 0,
			'feedbackFormStatus' => 0,
			'iscancel' => 1,
			'regionId' => 0,
			'customazationPrice' => null,
			'channelTypeId' => 0,
			'CancelReason' => null,
			'created_at' => date('Y-m-d H:i:s'),
			'updated_at' => date('Y-m-d H:i:s')
		);
		$invoiceReport_data = $orderDetails->cart_contents;
		
		$email = $orderDetails->email;
		$full_name = $orderDetails->full_name;
		
		$mysqlCon = DB::connection('mysql4');
				
		if($orderDetails->transactionStatus == "Success" || $orderDetails->transactionStatus == "Transaction Successful")
		{
			$invoiceID = $mysqlCon->table('invoice_finals')->insertGetId($invoicefinal_data);
			$invoice_number = 'AMR'.date('Y').date('m') . $invoiceID;
			$result = $mysqlCon->table('invoice_finals')
			->where('invoiceId', $invoiceID)
			->update(['invoiceNumber' => $invoice_number]);
		}else{
			unset($invoicefinal_data['dispatchDateStatus']);
			unset($invoicefinal_data['sharedWithDispatchTeam']);
			unset($invoicefinal_data['shareReportWithClient']);
			unset($invoicefinal_data['feedbackFormStatus']);
			unset($invoicefinal_data['customazationPrice']);
			$invoiceID = $mysqlCon->table('invoice_proformas')->insertGetId($invoicefinal_data);
			$invoice_number = 'PRO-AMR'.date('Y').date('m') . $invoiceID;
			$result = $mysqlCon->table('invoice_proformas')
			->where('invoiceId', $invoiceID)
			->update(['invoiceNumber' => $invoice_number]);
		}


	
		$reportOne = true;								
										

		foreach (unserialize($invoiceReport_data) as $reportData)
		{			
			$whereCondition=array('invoiceNumber' => $invoice_number,'licenseTypeName' => $reportData['options']['license'],'reportId' => $reportData['id']);	
			$checkIfReportExist = $mysqlCon->table('invoice_reports')->where($whereCondition)->first();
			if(empty($checkIfReportExist) || $checkIfReportExist == null)
			{				
				if($reportData['options']['type']=="CP")
				{
					$moreReportData = DB::table('bmr_report_cp')
						->join('categories', 'bmr_report_cp.rep_sub_cat_1_id', '=', 'categories.cat_id')   
						->where('bmr_report_cp.rep_id',$reportData['id'])   
						->select('bmr_report_cp.cp_code as rep_report_code', 'bmr_report_cp.rep_url', 'bmr_report_cp.rep_upcoming_published_status','categories.cat_title', 'categories.cat_id')
						->first();
					if(empty($moreReportData))
					{	
						$moreReportData->subcat_name = 0;							
						$moreReportData->rep_url = "";
						$moreReportData->rep_upcoming_published_status = "";
						$moreReportData->cat_id = "";
						$moreReportData->subcat_id = "";							
					}				
				}
				else
				{
					$moreReportData = DB::table('bmr_report')
						->join('categories', 'bmr_report.rep_sub_cat_1_id', '=', 'categories.cat_id')
						->join('subcategory', 'bmr_report.rep_sub_cat_2_id', '=', 'subcategory.subcat_id')      
						->where('bmr_report.rep_id',$reportData['id'])   
						->select('bmr_report.rep_report_code', 'categories.cat_title','subcategory.subcat_name','bmr_report.rep_url','bmr_report.rep_upcoming_published_status','categories.cat_id','subcategory.subcat_id')
						->first();
				}					
				if(empty($moreReportData))
				{
					$rawData['rep_report_code'] = @$reportData['options']['reportCode'];
					$rawData['cat_title'] = "";
					$rawData['subcat_name'] = "";					
					$rawData['rep_url'] = "";
					$rawData['rep_upcoming_published_status'] = "";
					$rawData['cat_id'] = "";
					$rawData['subcat_id'] = "";					
					$moreReportData = (object) $rawData;					
				}
				
				$dispatchMailSend = null;
				if($paymentFrom != 'Reportviewer' && $reportData['options']['license'] == 'Online Only')
				{
					$dispatchMailSend = $this->isOnlineOnly($fullpurchase, $reportData, $paymentDoneBYStudent, $orderDetails, $chapters);
				}
				if($reportOne)
				{
					/* 6/1/2020 */
					$request_type = 'Direct Purchase (DP)';
					$dealType = 71;
					$assignedTo = 25; /* Vinod */
					$description = 'AMR Direct Payment';
					$this->LeadsModel->generateLead($orderDetails, $reportData, $moreReportData, $paymentDoneBYStudent, $query_code, $dealType, $request_type, $assignedTo, $description);
					/* 6/1/2020 */
					$reportOne = false; 
				}
				$pdf = 0;
				$xlsformat = 0;
				$onlineonly = 0;
				if($reportData['options']['deliveryFormat'] == 'PDF'){
					$pdf = 1;
					$xlsformat = 0;
					$onlineonly = 0;
				}
				elseif($reportData['options']['deliveryFormat'] == 'Read Only')
				{
					$onlineonly = 1;
					$pdf = 0;
					$xlsformat = 0;
				}
				elseif($reportData['options']['deliveryFormat'] == 'Excel')
				{
					$xlsformat = 1;
					$onlineonly = 0;
					$pdf = 0;
				}
				elseif($reportData['options']['deliveryFormat'] == 'PDF/Excel')
				{
					$pdf = 1;
					$xlsformat = 1;
					$onlineonly = 0;
				}
				$insertInvoiceReport = array(
					'invoiceNumber' => $invoice_number,
					'reportId' => $reportData['id'],
					'reportTitle' => $reportData['name'],
					'reportCode' => $moreReportData->rep_report_code,
					'reportCategory' => $moreReportData->cat_title,
					'reportSubcategory' => $moreReportData->subcat_name,
					'reportPrice' => $reportData['price'],

					'xlsformat' => $xlsformat,
					'pdf' => $pdf,
					'cdrom' => 0,
					'hardcopy' => 0,
					'onlineonly' => $onlineonly,

					'licenseTypeName' => $reportData['options']['license'],
					'report_id' => $reportData['id'],
					'report_status' => $reportData['options']['pu_status'],
					'updatedReportPrice' => @$reportData['updatedReportPrice'],
					'dataPrice' => @$reportData['dataPrice'],
					'mailDispatchedIfOnlineOnly' => $dispatchMailSend
				);
				$mysqlCon->table('invoice_reports')->insert($insertInvoiceReport);  
			}
		}

		if($orderDetails->transactionStatus == "Success" || $orderDetails->transactionStatus == "Transaction Successful")
		{
		$invoice = $mysqlCon->table('invoice_finals')
                ->join('activities', 'invoice_finals.activitiesId', '=', 'activities.activitiesId')
                ->join('invoice_types', 'invoice_finals.invoiceTypeId', '=', 'invoice_types.invoiceTypeId')
                ->join('lead_sources', 'invoice_finals.leadSource', '=', 'lead_sources.leadSourceId')   
                ->join('tbl_license_type', 'invoice_finals.licenseType', '=', 'tbl_license_type.licenseTypeId') 
                ->join('type_of_sales', 'invoice_finals.typeOfSale', '=', 'type_of_sales.typeOfSaleId')      
                ->join('payment_modes', 'invoice_finals.paymentModeId', '=', 'payment_modes.paymentModeId')        
                ->where('invoice_finals.invoiceId',$invoiceID)   
                ->select('invoice_finals.*', 'invoice_types.invoiceTypeName', 'lead_sources.leadSourceTitle','activities.activitiesTitle','tbl_license_type.licenseTypeTitle','type_of_sales.typeOfSaleTitle','payment_modes.paymentModeTitle')
				->first();
		}else{
			

			$invoice = $mysqlCon->table('invoice_proformas')
			->join('activities', 'invoice_proformas.activitiesId', '=', 'activities.activitiesId')
			->join('invoice_types', 'invoice_proformas.invoiceTypeId', '=', 'invoice_types.invoiceTypeId')
			->join('lead_sources', 'invoice_proformas.leadSource', '=', 'lead_sources.leadSourceId')   
			->join('tbl_license_type', 'invoice_proformas.licenseType', '=', 'tbl_license_type.licenseTypeId') 
			->join('type_of_sales', 'invoice_proformas.typeOfSale', '=', 'type_of_sales.typeOfSaleId')      
			->join('payment_modes', 'invoice_proformas.paymentModeId', '=', 'payment_modes.paymentModeId')        
			->where('invoice_proformas.invoiceId',$invoiceID)   
			->select('invoice_proformas.*', 'invoice_types.invoiceTypeName', 'lead_sources.leadSourceTitle','activities.activitiesTitle','tbl_license_type.licenseTypeTitle','type_of_sales.typeOfSaleTitle','payment_modes.paymentModeTitle')
			->first();
		}			


		$invoice_report = $mysqlCon->table('invoice_reports')->where('invoiceNumber',"=",$invoice->invoiceNumber)->get();
		
		$subjectLine = 'Success: Payment Successful.';
		$sendData['full_name'] = $full_name;
		$sendData['reportData'] = $invoiceReport_data;
		$sendData['Gateway'] = $paymentThrough;
		$sendData['invoiceNumber'] = $invoice->invoiceNumber;
		$sendData['grandTotal'] = $orderDetails->grandTotal;
		
		$fromEmail = 'noreply@alliedmarketresearch.com';
		if($paymentDoneBYStudent == 'Yes' && !$fullpurchase)
		{
			$fromEmail = 'ethan.williams@alliedmarketresearch.com';
		}
		$mailView = 'mailTemplates.dpinvoice';
		if($paymentFrom == 'Reportviewer')
		{
			$mysql5 = DB::connection('mysqlAVE');
			$checkoutType = $mysql5->table('reportViewer_checkout')
							->select('checkoutType')
							->where('checkout_id',"=",$orderDetails->checkout_id)
							->first();
			$sendData['checkoutType'] = $checkoutType->checkoutType;
			$mailView = 'mailTemplates.reportViewerMail';

		}
		$data = array(
			'invoice' => $invoice,
			'invoice_report' => $invoice_report
		);

		$file_name= $invoice->invoiceNumber;

		$isAvenue = explode(' ',$paymentFrom);
		if($isAvenue[0] == 'Avenue')
		{
			$avenueCon = DB::connection('mysqlAVE');
			$data['packageDetail'] = $avenueCon->table('packages')->where('id',$isAvenue[1])->first();
			// PDF::html('invoiceFinal.avenueInvoice',array('data' => $data),$file_name);
		}
		else
		{
			// PDF::html('invoiceFinal.pdfview',array('data' => $data),$file_name);
		}
		/*$attachmentLink= public_path("/assets/invoices/$file_name".".pdf");
		
		Mail::send($mailView, $sendData, function($message) use ($email,$full_name,$subjectLine,$attachmentLink, $fromEmail) { 
			$message->from($fromEmail,'Allied Market Research')
				// ->to($email,$full_name)	
				->to('krutik.dhameliya@alliedanalytics.com','Krutik Dhameliya')
				// ->bcc('krutik.dhameliya@alliedanalytics.com','Krutik Dhameliya')				
				->cc('krutik.dhameliya@alliedanalytics.com','Krutik Dhameliya')				
				->subject($subjectLine)	
				// ->attach($attachmentLink);
		});*/
	}
	public function customPaymentMail($orderDetails, $paymentThrough)
	{
		$invoiceReport_data = $orderDetails->cart_contents;
		$email = $orderDetails->email;
		$full_name = $orderDetails->full_name;
		$subjectLine = 'Success: Payment Successful.';
		$sendData['full_name'] = $full_name;
		$sendData['reportData'] = $invoiceReport_data;
		$sendData['Gateway'] = $paymentThrough;
		$sendData['grandTotal'] = $orderDetails->grandTotal;
		Mail::send('mailTemplates.customPaymentInvoice',$sendData, function($message) use ($email,$full_name,$subjectLine)
		{ 
			$message->from('purchase@alliedmarketresearch.com','Allied Market Research')
				->to($email,$full_name)		
				->subject($subjectLine);
		});
	}
	/* code by krutik ends here */

	public function getCheckoutData($recurring = false,$order_no){
		if ($recurring === true) {
        } else {	
			$total = 0;
			$orderData=DB::table('amr_cp_orders')->where('order_no', $order_no)->first();
			$data = [];
			$data['items'] = [
				 [
					 'name' =>$order_no.'Report Order',
					 'price' =>$orderData->grandTotal,
					 'qty' => 1
				 ]
			];
			$data['invoice_id'] = time()."-".$order_no;
			$data['order_id'] = $orderData->id;
			$data['order_no'] = $order_no;
			$data['invoice_description'] = "Order $order_no Invoice";
			$data['return_url'] = url('/paypal-callback');
			$data['cancel_url'] = url('api/paypalCancel/'.$orderData->id);				
		}
		$data['total'] =$orderData->grandTotal;
		return $data;
	}
	public function paypalCancel(Request $request) 
	{
		if ($request->isMethod('get')) 
		{
			$responseArray = array();
			$keyArray = array();
			$valueArray = array();
			$orderId =request()->segment(3);
			$token = $request->get('token');
			$provider = PayPal::setProvider('express_checkout');
			$response = $provider->getExpressCheckoutDetails($token);
			$updateArray = array(
				"transactionStatus" => "Cancel",
				"transactionDetails" => serialize($response),
			);
			DB::table('amr_cp_orders')
			->where('id', $orderId)
			->update($updateArray);
			if($orderId !='') 
			{
				$orderDetails=$this->CheckoutModel->getOrderDetails($orderId,false);
				if(!empty($orderDetails)) 
				{
					$pageData['metaTitle']="Pay Using Paypal - AMR";
					$pageData['metaDescription']=""; 
					$pageData['metaKeyword']="";    
					$pageData['headerUrl'] = "Become a Reseller";		
        			$pageData['headerLink'] = "become-a-reseller";     
					$pageData['getCategories']=$this->HomeModel->get_active_categories();
					$pageData['getCmsHeader']=$this->HomeModel->getHeaders();
					$pageData['getCountry']=$this->CmsFooterModel->get_countries();
					$pageData['orderDetails'] = $orderDetails;
					$cartContent=unserialize($orderDetails->cart_contents);								
					$pageData['cartContent'] = $cartContent;				
					$pageData['checkoutId'] = $orderDetails->checkout_id;
					$pageData['order_no'] = $orderDetails->order_no;
					$pageData['orderId'] = $orderId;
					return view('paymentGateway.cancel_paypal',$pageData);
				}
				else
				{
					return redirect('shopping-cart');
				}
			}
		}
		else
		{
			return redirect('shopping-cart');
		}
	}	
	public function paypalFail(Request $request)
	{
  		  Session::flash('message', 'Payment Failed');
		  Session::flash('alert-info', 'Failed');
		  Session::flash('alert-class', 'alert-danger');
	}
	public function createCheckoutLinkFromCRM(Request $request) {
		$input = $request->all();
		$returnId="";
		if(!empty($input) && !empty($input['cart_content'])) {
			$cart_content=(serialize($input['cart_content']));
			$formdata=(serialize($input['formdata']));
			$insertData=array(
				"id" 				=> $input['id'],
				"cart_contents" 	=> $cart_content, 
				"formdata" 			=> $formdata,	
				"type" 				=> 'manual',	
				"password" 			=> $input['password'],
				"checkout_status" 	=> 'y',
				"invoiceId" 		=> $input['invoiceId'],	
				"internetHandlingCharge" => $input['internetHandlingFee'],
				"campaignBy" 		=> $input['campaignBy'],
				"campaignCode" 		=> $input['campaignCode']				
			);
			//DB::table('amr_checkout')->updateOrInsert(['invoiceId' => $input['invoiceId']],$insertData);
			DB::table('amr_checkout')->insert($insertData);
			$returnId=$input['id'];
		}
		return $returnId; 
	}
	public function createKTCheckoutLinkFromCRM(Request $request) {
		$input = $request->all();
		$returnId="Invalid Parameters Submitted.";
		if(!empty($input) && !empty($input['formdata']) && !empty($input['cart_content']))  {	
		
			$Email 			= preg_replace('/\s+/', ' ', $input['formdata']['Email']);
			$Password 		= preg_replace('/\s+/', ' ', $input['formdata']['Password']);
			$Name 			= preg_replace('/\s+/', ' ', $input['formdata']['Name']);
			$Phone 			= preg_replace('/\s+/', ' ', $input['formdata']['Phone']);
			$Designation 	= preg_replace('/\s+/', ' ', $input['formdata']['Designation']);
			$Company 		= preg_replace('/\s+/', ' ', $input['formdata']['Company']);
			$address_one 	= preg_replace('/\s+/', ' ', $input['formdata']['address_one']);			
			$address_two 	= preg_replace('/\s+/', ' ', $input['formdata']['address_two']);
			$City 			= preg_replace('/\s+/', ' ', $input['formdata']['City']);
			$Country 		= preg_replace('/\s+/', ' ', $input['formdata']['Country']);
			$State 			= preg_replace('/\s+/', ' ', $input['formdata']['State']);		
			$Zip 			= preg_replace('/\s+/', ' ', $input['formdata']['Zip']);
			$cartCategory 	= preg_replace('/\s+/', ' ', $input['cart_content']['name']);
			$subscType 		= preg_replace('/\s+/', ' ', $input['cart_content']['subscriptionType']);
			$price 			= preg_replace('/\s+/', ' ', $input['cart_content']['price']);
			
			$checkCondition=array("email"=>$Email);			
			$checkIfUserExists=DB::table('kt_users')->select('email')->where($checkCondition)->first();			
			if(!empty($checkIfUserExists)) {
				$returnId='user already exist';
			}
			else
			{
				$addUser=array(
				"email" => $Email, 
				"username" => $Name, 
				"password" => $Password,
				"name" => $Email,
				"mobile" => $Phone,
				"job_destination" => $Designation,
				"organisation_name" => $Company,
				"address_one" => $address_one,
				"address_two" => $address_two, 
				"city" => $City, 
				"country" => $Email, 
				"state" => $State, 
				"zip_code" => $Zip, 
				"roleId" => '2', 
				"isDeleted" => '0',
				"createdBy" => '1',
				"createdDtm" => date("Y-m-d H:i:s"),
				"terms_and_condition" => '0',
				"user_type" => 'Client');			
				$last_id = DB::table('kt_users')->insertGetId($addUser);
				
				if($last_id) {					
					$kts_code = 'KT'.date('Y').$last_id;
					$username = $kts_code.'@alliedmarketresearch.com';				
					$updateUser=array("username"=>$username,"kts_code"=>$kts_code);
					DB::table('kt_users')->where('userId', $last_id)->update($updateUser);					
					$category = array(
					'1'=>'Life Sciences',
					'6'=>'Consumer Goods',
					'7'=>'Materials and Chemicals',
					'8'=>'Construction & Manufacturing',                  
					'9'=>'Food and Beverages',                
					'10'=>'Energy and Power',                
					'11'=>'Semiconductor and Electronics',                
					'12'=>'Automotive and Transportation',                
					'13'=>'ICT & Media',
					'14'=>'Aerospace & Defense',    
					'15'=>'BFSI');
					$key = array_search ($cartCategory, $category);					
					$addCartDetails=array(
					"userId" => $last_id, 
					"title" => $cartCategory, 
					"typeId" => '3',
					"subscriptionId" => $subscType,
					"quantity" => '1',
					"price" => $price,
					"id" => $key,
					"catId" => $key,
					"createdDtm" => date("Y-m-d H:i:s"), 
					"status" => '0', 
					"isDeleted" => '1');			
					DB::table('kt_cart')->insertGetId($addCartDetails);				
					$returnId=$last_id.' '.$kts_code;				
				}
			}			
		}
		echo $returnId; 
	}
	public function removeReport(Request $request)
	{
		$input = $request->all();
		$Status="Failed";
		$Message="Failed";
		$cartItems=0;
		$cartItem=array();
		$actualCheckoutId=0;
		if($input['cartRowId']!='') {			
			if(count(Cart::content())>0) {		
				@Cart::remove($input['cartRowId']);					
				$getCart=Cart::content()->reverse();	
				if(count(Cart::content())>0) {
					foreach($getCart as $items) {					
						$options=array(
							"reportCode" => $items->options->reportCode,
							"pu_status" => $items->options->pu_status,
							"discountPercentage" => $items->options->discountPercentage,
							"originalPrice" => $items->options->originalPrice,
							"license" => $items->options->license,
							"rep_url" => $items->options->url,
							"type" => $items->options->type,
							"deliveryFormat" => $items->options->deliveryFormat,
							"chapter_names" => $items->options->chapter_names,
							"message" => $items->options->message,
							"applicableLicenceTypes" => $items->options->applicableLicenceTypes,
						);					
						$cartItem[$items->rowId]=array(
							"rowId"=>$items->rowId,
							"id"=>$items->id,
							"name"=>$items->name,
							"price"=>$items->price,
							"qty"=>$items->qty,
							"options"=>$options);		
					}
				}
				if(!empty($cartItem)) 
				{
					$updatearray = array('cart_contents'=> serialize($cartItem));					
					$returnres=DB::table('amr_checkout')
						->where(DB::raw('md5(id)') , trim($input['checkout']))
						->update($updatearray);						
					$Status = 'Success';
					$Message = 'Report successfully removed from the cart.';										
				}
				$checkoutDetails=$this->CheckoutModel->checkCheckoutExists(trim($input['checkout']),true);
				if(!empty($checkoutDetails)) {
					$actualCheckoutId=$checkoutDetails->id;					
				}				
				$cartItems=count(Cart::content());				
			}
			else
			{
				$checkoutDetails=$this->CheckoutModel->checkCheckoutExists(trim($input['checkout']),true);
				if(!empty($checkoutDetails)) {
					$actualCheckoutId=$checkoutDetails->id;
					$cartContent=$checkoutDetails->cart_contents;
					if($cartContent!='')
					{
						$cartContent=unserialize($cartContent);
						unset($cartContent[$input['cartRowId']]);						
						$updatearray = array('cart_contents'=> serialize($cartContent));					
						$returnres=DB::table('amr_checkout')
						->where(DB::raw('md5(id)') , trim($input['checkout']))
						->update($updatearray);						
						$Status = 'Success';
						$Message = 'Report successfully removed from the cart.';					
					}
				}
				// Required to get latest reports count from cart
				$reCheckoutDetails=$this->CheckoutModel->checkCheckoutExists(trim($input['checkout']),true);
				if(!empty($reCheckoutDetails)) {
					$reCartContent=$reCheckoutDetails->cart_contents;
					if($reCartContent!='')
					{
						$reCartContent=unserialize($reCartContent);
						$cartItems=count($reCartContent);						
					}
				}
			}			
			if($cartItems==0 && $actualCheckoutId>0) {
				DB::table('amr_checkout')->where('id',"=",$actualCheckoutId)->delete();
				$Status='Success';
				$Message = 'Checkout Deleted Successfully.';
			}			
		}
		$return =array("Status" => $Status,"Message" => $Message,"iscartEmpty" => $cartItems);
		echo json_encode($return);
	}
	function setCloseAndWon($queryCode)
	{
		$currentStage='Close and Won';
		$currentTime=date('Y-m-d H:i:s');
		$leadId=0;
		if(trim($queryCode)!='')
		{
			$studentsQuery = DB::connection('mysql4')->table('leads')->select('id')->where('query_code',$queryCode)->first();
			if(!empty($studentsQuery)) {
				$leadId=$studentsQuery->id;
			}
			if($leadId>0)
			{
				$activityLogCondition = array(
				'user_id' => 25,                        
				'text' => 'Change stage to Close and Won',
				'source_type' => 'App\Models\Lead',
				'source_id' => $leadId,
				'action' => 'stage_changed',
				'created_at' => $currentTime,
				'updated_at' => $currentTime);
				
				$stageLogCondition = array(
				'id' => $leadId, 
				'stage_name' => $currentStage);
				
				$leadsCondition = array(
				'currentStage' => $currentStage,
				'lastActivityDate' => $currentTime,
				'dealStatus'=> 'Won',
				'dealLostStatus' => "",
				'queryLostComment' => "",
				'closeDate' => $currentTime);
				
				DB::connection('mysql4')->table('leads')->where('id', $leadId)->update($leadsCondition);	
				DB::connection('mysql4')->table('stages_log')->insert($stageLogCondition);			
				DB::connection('mysql4')->table('activity_log')->insert($activityLogCondition);
			}
		}
	}	
}

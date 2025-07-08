# Module for CS-Cart 4.x

## Installation

To install the payment module, please follow these steps:

1. Log in to the admin panel of your store.

2. Navigate to **Add-ons → Manage add-ons**:  
   `/admin.php?dispatch=addons.manage`

3. Upload the archive containing the payment module.

4. Add a new payment method via:  
   **Administration → Payment methods**  
   `/admin.php?dispatch=payments.manage`

  - On the **General** tab, fill in:
    1. **Name** – Visa/Mastercard (by card)
    2. **Processor** – Flitt

  - On the **Configure** tab, fill in:
    1. **Merchant ID** – your merchant identifier
    2. **Password** – your merchant secret key
    3. **Currency** – your merchant currency
    4. **Transaction method** – Sale / Hold
    5. **Order statuses**

5. Click **Create** to save the payment method.

> **Note:**  
> If the *Hold* transaction method is selected, the funds will be captured when the  
> **order status with frozen funds** is changed to the **paid order status** you specified.
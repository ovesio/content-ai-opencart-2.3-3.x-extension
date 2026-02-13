# Ovesio - Content AI Extension for OpenCart 2.3 - 3.x

## License and Disclaimer
This package is released under the **MIT License**.
We are **not responsible** for any malfunction or improper behavior caused by the use of this package.
This package is provided as an **example integration**.
For production-ready integrations, we highly recommend using our official API endpoints and documentation, available at [https://ovesio.com/docs](https://ovesio.com/docs).

---

## Overview
The Ovesio - Content AI Extension integrates OpenCart with [Ovesio.com](https://ovesio.com), enabling AI-powered translations and automatic content generation (descriptions and SEO meta tags) for your e-commerce store.

---

## Key Features

### Compatibility
- Fully compatible with OpenCart **2.3 - 3.x**
- Also compatible with SEO enhancement extensions such as:
  - **Complete SEO**
  - **SEO Mega KIT PLUS**

### Translation Capabilities
- Translations are performed **automatically in the background**
- Once a translation is completed, it is updated on the website — translations are **not instant**
- Translations are available for:
  - Product names
  - Descriptions
  - Meta titles and keywords
  - Specification attributes
  - Attribute groups
  - Product options
  - *(Information pages support coming soon)*
- Translations are **not repeated** unless the original content has changed

### Description Generation
- Automatic AI-generated descriptions for both **products** and **categories**
- Custom logic for when to generate:
  - For products/categories with existing descriptions shorter than a defined character limit
  - For **out-of-stock products**
  - For **disabled products and categories**
- Two modes available:
  - **One time only**
  - **On each resource update**
- Descriptions are also performed **in the background**, not instantly

### SEO Meta Tag Generation
- Fully automated generation of SEO **meta titles**, **meta descriptions**, and **meta keywords** using Ovesio - Content AI
- Configurable from the **AI SEO MetaTags Generator** tab
- Available for both **products** and **categories**
- SEO meta tag generation supports:
  - **Out-of-stock products**
  - **Disabled products and categories**
- Two generation modes supported:
  - **One time only** (meta tags are generated a single time)
  - **On each product or category update** (meta tags are regenerated every time a resource is edited)

### AI Description Generator Options
From the **AI Description Generator** tab:
- Enable/disable product and category description generation
- Set thresholds:
  - Ignore product descriptions longer than X characters
  - Ignore category descriptions longer than X characters
- Set inclusion logic for out-of-stock and disabled products/categories
- Choose when new descriptions should be created:
  - One time only
  - On each update

### Cron Integration
- Automate processing with a cron job:
  ```
  */5 * * * * curl -k -L "https://yourdomain.com/index.php?route=extension/module/ovesio/cronjob&hash=YOUR_HASH" > /dev/null 2>&1
  ```
- The cron job:
  - Runs every 5 minutes
  - Processes up to **50 entries per execution**
  - Triggers translations, description and meta tag generation
- The cron URL and hash are available in the **General** tab

---

## Installation

### Step 1: Prerequisites - Language Configuration
**Note: This step is only necessary if you intend to translate your store into a new language.**

Ovesio is capable of translating site content such as products, attributes, options, and categories. However, for the translation of static website text (e.g., standard buttons, menu items, system messages), you must install and configure a dedicated language pack from OpenCart.

1. **Download a Language Pack**: Visit [opencart.com](https://www.opencart.com) and download the language pack for your desired target language.
2. **Install and Configure**: Install the language pack and configure it in your OpenCart admin panel under **System > Localisation > Languages** (or **Settings > Localisation > Languages**).
3. **Ready for Ovesio**: Once the language is installed and active in OpenCart, you can proceed to configure the Ovesio module to automatically translate your products into this language.

### Step 2: Upload and Install the Module
1. Download the module archive called ovesio.ocmod.zip.
2. Navigate to **Extensions > Installer** in your OpenCart admin panel.
3. Upload the module file and wait for installation to complete.
4. Go to **Extensions > Modifications** and click the **Refresh** button.
5. Navigate to **Extensions > Modules** and find `Ovesio - Content AI`.
6. Click **Install** and then **Edit** to configure the module.

### Step 3: Configure the Module

#### General Tab
- Enable the module
- Set the **API URL** (e.g., `https://api.ovesio.com/v1/`)
- Enter your **API Token**
- Select your **catalog language**
- Set up the **cron job** for automatic processing

#### AI Description Generator Tab
- Enable/disable product and category description generation
- Set character thresholds for descriptions
- Include/exclude out-of-stock or disabled entries
- Choose generation frequency:
  - One time only
  - On each update

#### AI SEO MetaTags Generator Tab
- Enable/disable SEO meta tag generation for products and categories
- Choose whether to include:
  - Out-of-stock products
  - Disabled products and categories
- Choose generation frequency:
  - One time only
  - On each update

#### Translate Settings Tab
- Enable/disable translation features
- Select languages and source/target mappings
- Choose translatable fields (Name, Description, Tags, Meta Title, Meta Description, Meta Keywords)
- Enable translation of additional product attributes and options

---

## Usage Summary

- Descriptions, meta tags, and translations are executed asynchronously in the background but can also be can be triggered once or on every update, based on configuration
- Translations are processed once unless content is modified
- Use feeds for large catalogs and ensure cron is set up for full automation

---

## Support
This module is developed and maintained by **Ovesio.com**.
For documentation and API references, visit [https://ovesio.com/docs](https://ovesio.com/docs).

## Author

Aweb Design SRL

## License

MIT License. See LICENSE file for details.

## Disclaimer

This module is provided "AS IS", without warranty of any kind, express or implied.
The authors and contributors are not liable for any claim, damages, or other liability,
whether in an action of contract, tort, or otherwise, arising from, out of, or in connection
with the module or the use or other dealings in the module.

This is an open-source community module and is not an official module of OpenCart

This module may rely on third-party services/APIs. Availability and output quality may vary.

Use this module at your own risk. Always test in a staging environment before deploying to production.
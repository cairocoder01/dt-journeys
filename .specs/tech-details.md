# Admin Interface

Built in the frontend (/admin/journeys) like user management so a role can be given to non-admin users for management of journeys.

# Data Architecture

## Journey

- Name  
- Category/Group  
  - Allow grouping by ministry model (e.g. T4T, Zume, etc.)  
- Roles  
  - Which DT roles does this apply to?  
  - Digital Filterer or Multiplier (others?)  
  - Dispatcher should have access to all

## Journey Stage

- Name  
- Description (short)  
- Instructions (long, rich text)  
- Attachments (links, PDFs, images, or other resource material)  
- Related Fields  
  - Select DT fields that are relevant to fill out at this stage  
- Success Action Label (optional)  
  - Optionally rename Complete to a different label  
- 

## User Journey Stage (instance of a stage)

- Post ID  
- Stage ID  
- Status  
  - Not Started  
  - Started  
  - Paused  
  - Incomplete  
  - Complete  
  - Skipped  
- Status Date  
  - Keep track of date of last status change  
  - Could get this from activity log, but easier to display completion date if it’s saved here  
- Comment / Note

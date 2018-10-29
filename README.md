# REDCap External Module: DDP Per Project

Luke Stevens, Murdoch Children's Research Institute

Enables project-specific DDP configuration (i.e. as opposed to the default system-level only DDP configuration).
Facilitates DDP into a project using another REDCap project (in the same REDCap instance) as the DDP source.

## Configuration: Project Settings
### External Source
The configuration of an external source mirrors the Control Center fields that the module overrides, namely:
1. Data source name
2. Metadata web service url
3. Data web service url
4. User access web service url (optional)

### REDCap Project as DDP Source
A different set of options is applicable when setting up a REDCap project as the DDP source:
1. Source REDCap project: choose from a list of projects that you are a user of.
2. Source project field to use for record lookup
   - Lookup in source project's Record ID field.
   - Lookup in source project's Secondary ID field (if one is set).
3. Is the lookup of records in the source project sensitive to the user's DAG in the destination project?
   - Yes: lookup will return only records in the DAG in the source project with a full DAG name matching the full DAG name of the user's DAG in the destination project.
   - No: lookup can return records from any source project DAG
4. What permissions are required for this project's users in the source project?
   - None: this project's users do not need to be users in the source project
   - Any: users need only non-expired access to source project with any permission level
   - Full Export: Only users with \"Full Data Set\" permission in source project can perform DDP
5. Enable logging to log file in module directory: when enabled, requests to and results from web services is logged to project_ddp.log file in module directory.
6. Override for 'secret' query string parameter of web service calls. **Leave blank*** except when when required for testing e.g. via curl or Postman.

## Temporal and Non-Temporal Fields of a REDCap Project
The following are considered temporal fields:
* Fields on a repeating form containing a date[/time] field 
* Fields on a form containing a date[/time] field in a repeating event 
* Fields on a non-repeating form containing a date[/time] used in multiple events of a longitudinal project

The following are considered non-temporal fields:
* Fields on a non-repeating form in a non-longitudinal project
* Fields on a non-repeating form in a longitudinal project where the form is associated with only one event
* Fields on a repeating form or in multiple events of a longitudinal project and the form contains no date[/time] field

In this latter case, only the first value (lowest event/instance id) will be returned.

## Multi-Arm Projects
Multi-arm configurations have not been considered in the development of this module.

## Min REDCap Version Requirement
Requires REDCap 8.3.2 for DDP of checkbox values.

## Example Web Service Calls
For the purpose of these examples
1. REDCap is running on the user's machine (i.e. localhost)
2. The username being simulated is "luke1"
3. The project id of the destination project is 167
4. The module's project setting "override 'secret'" is set to "test"
See the **Dynamic Data Pull (DDP) - Custom** page in your REDCap Control Center for more information; especially the **Technical Specification for DDP web services (PDF)**.

### User Web Service
URL: `https://localhost/redcap/api/?NOAUTH&type=module&prefix=project_ddp&page=project_ddp&pid=167&secret=test&service=user`  
Content Type: `application/json`  
Body: `{"user":"luke1","project_id":"167","redcap_url":"https:\/\/localhost\/redcap\/"}`  
Return: `1`  

### Metadata Web Service
URL: `https://localhost/redcap/api/?NOAUTH&type=module&prefix=project_ddp&page=project_ddp&pid=167&secret=test&service=metadata`  
Content Type: `application/json`  
Body: `{"user":"luke1","project_id":"167","redcap_url":"https:\/\/localhost\/redcap\/"}`  
Return: `[{"field": "record_id","label": "Record ID","description": "text","temporal": 0,"category": "baseline","identifier": "1"},{ ... }]`  

### Data Web Service
URL: `https://localhost/redcap/api/?NOAUTH&type=module&prefix=project_ddp&page=project_ddp&pid=167&secret=test&service=data`  
Content Type: `application/json`  
Body: `{"user":"luke1","project_id":"167","redcap_url":"https:\/\/localhost\/redcap\/","id":"2","fields":[{"field":"sex"},{"field":"ethnicity"},{"field":"visitdate","timestamp_min":"2018-01-01","timestamp_max":"2018-01-03"}]}`  
Return: `[{"field": "sex","value": "1"},{"field": "ethnicity","value": "1"},{"field": "ethnicity","value": "3"},{"field": "visitdate","value": "2018-01-01","timestamp": "2018-01-01"}],{"field": "visitdate","value": "2018-01-03","timestamp": "2018-01-03"}]`  
____

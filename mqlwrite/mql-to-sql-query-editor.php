<?php
include 'config.php';
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
 "http://www.w3.org/TR/html4/loose.dtd">
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <title>MQL to SQL Query Editor</title>
        <style type="text/css">
            * {
                font-family: arial;
                font-size: 10pt;
            }
            
            body {
            }
            
            table {
                height: 100%;
                position: absolute;
                top: 0px;
                bottom:0px;
                left: 0px;
                right:0px;
                width:100%;
            }
            td {
                vertical-align: top;
                align: left;
            }
            
            textarea {
                border-style:solid;
                border-width:1px;
                color:blue;
                background-color:rgb(255,255,170);
                font-family: monospace;
                width: 100%;
                height:100%;
                top:0px;
                bottom:0px;
            }

            .header {
                height: 12px;
            }
            
            .top {
                height: 35px;
            }
            
            .left {
                width: 75px;
                height: 50%;
            }
            
            .right {
                height: 50%;
            }
            
            button {
                
            }
        </style>
    </head>
    <body>
        <table cellpadding="0" callspacing="0">
            <tr>
                <td class="top" colspan="2">
                    <a  href="http://code.google.com/p/mql-to-sql/" 
                        target="mql-to-sql"
                        title="mql-to-sql project homepage hosted at google code."
                    >Project: mql-to-sql</a>
                    |
                    <a
                        href="http://www.freebase.com/docs/data"
                        target="freebase"
                        title="Homepage of Freebase, the open collaborative data project that uses MQL as its native query language, and main source of inspiration for this project."
                    >Freebase</a>
                    |
                    <a
                        href="http://www.freebase.com/docs/mql"
                        target="freebase"
                        title="MQL reference. This is the most complete description of the MQL query language today. Note that mql-to-sql currently implements a subset of the MQL grammar, with some extensions."
                    >MQL Reference</a>
                    |
                    <a
                        href="https://docs.google.com/viewer?url=http://download.freebase.com/MQLcheatsheet-081208.pdf"
                        target="freebase"
                        title="MQL Cheatsheet provides a concise overview of MQL language features"
                    >MQL Cheatsheet</a>
                    |
                    <a
                        href="http://dev.mysql.com/doc/sakila/en/sakila.html"
                        target="mql-to-sql"
                        title="Documentation to the Sakila sample database. This query editor allows you to query this sample database using MQL."
                    >Sakila Sample Database</a>
                    |
                    <a
                        href="<?php echo($metadata_file_name);?>"
                        target="mql-to-sql"
                        title="The (automatically generated) map used to translate MQL to SQL against the Sakila sample database"
                    >mql-to-sql Schema for Sakila</a>
                </td>
            </tr>
            <tr>
                <td class="left" rowspan="2">
                    <button 
                        type="button"
                        id="btnExecute"
                        title="Hit this button to execute your MQL query against the Sakila sample database"
                    >Execute</button>
                </td>
                <td class="header">MQL Query:</td>
            </tr>
            <tr>
                <td class="right">
                    <textarea 
                        id="txtQuery" 
                        rows="20" 
                        title="Type your MQL query here."
                    >
{
  "type":"/sakila/customer",
  "customer_id":1,
  "first_name":null,
  "last_name":null,
  "fk_rental_customer":[{
      "rental_id":null,
      "rental_date":null,
      "return_date":null,
      "fk_rental_inventory":{
        "store_id":null,
        "fk_inventory_film":{
          "title":null,
          "rental_rate":null,
          "rental_duration":null
        }
      }
    }
  ],
  "fk_customer_address":{
    "address":null,
    "postal_code":null,
    "phone":null,
    "fk_address_city":{
      "city":null,
      "fk_city_country":{
        "country":null
      }
    }
  },
  "fk_payment_customer":[{
      "rental_id":null,
      "payment_date":null,
      "amount":null
    }
  ]
}
                    </textarea>
                </td>
            </tr>
            <tr><td></td><td class="header">Result:</td></tr>
            <tr>
                <td>
                </td>
                <td>
                    <textarea 
                        id="txtResult" 
                        rows="20"
                        title="This is where the JSON result of you MQL query will be returned"
                    ></textarea>
                </td>
            </tr>
        </table>

        
        <!-- YUI - required for JSON "pretty print" support -->
<!--        
        <script type="text/javascript" src="http://yui.yahooapis.com/combo?2.8.0r4/build/yahoo/yahoo.js&amp;2.8.0r4/build/event/event.js&amp;2.8.0r4/build/connection/connection_core.js&amp;2.8.0r4/build/json/json.js"></script> 
-->
        <script type="text/javascript" src="<?php echo($yui_url);?>/build/yahoo/yahoo.js"></script>
        <script type="text/javascript" src="<?php echo($yui_url);?>/build/event/event.js"></script>
        <script type="text/javascript" src="<?php echo($yui_url);?>/build/connection/connection_core.js"></script>
        <script type="text/javascript" src="<?php echo($yui_url);?>/build/json/json.js"></script> 
        
        <!-- Script for the query editor -->
        <script type="text/javascript">
            (function () {

                function validateJSON(txt){
                    var o = YAHOO.lang.JSON.parse(txt);
                    var t = YAHOO.lang.JSON.stringify(o, null, 2);
                    return t;
                }
            
                function setResult(v){
                    document.getElementById("txtResult").value = v;
                }

                function doMQLReadRequest(query){
                    var queryEnvelope = "{\"query\":" + query + ", \"debug_info\": true}";
                    var url = "index.php?query=" + encodeURIComponent(queryEnvelope);
                    YAHOO.util.Connect.asyncRequest("GET", url, {
                        "success": handleMQLReadResponse,
                        "failure": handleMQLReadFailure
                    }, null);
                }                
                
                function handleMQLReadFailure(response){
                    alert("Snap, something went wrong :("+
                    "\n"+ "status: " + response.status + 
                    "\n"+ "message: " + response.statusText
                    );
                }
                
                function handleMQLReadResponse(response){
                    var t, rt = response.responseText;
                    try {
                        t = validateJSON(rt);                        
                    } catch(e) {
                        t = "Error in response:\n" + e + 
                            "\nRaw responseText:\n\n" + rt;
                    }
                    setResult(t);
                }

                var rst = document.getElementById("txtResult");
                
                document.getElementById("btnExecute").onclick = function(){
                    setResult("");
                    var txtQuery = document.getElementById("txtQuery");
                    var query = txtQuery.value;
                    txtQuery.value = "";
                    try {
                        var o = YAHOO.lang.JSON.parse(query);
                        //for pretty printing, we add 2 spaces
                        txtQuery.value = YAHOO.lang.JSON.stringify(o, null, 2);
                        //for the actual request, we omit spaces (micro-optimization)
                        doMQLReadRequest(YAHOO.lang.JSON.stringify(o, null, 0));
                    } catch (e) {
                        setResult(e);
                        txtQuery.value = query;
                    }
                }
            })();
        </script>
        <!--
            Google analytics tracker.
        -->
        <script type="text/javascript">

          var _gaq = _gaq || [];
          _gaq.push(['_setAccount', 'UA-12344450-6']);
          _gaq.push(['_trackPageview']);

          (function() {
            var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
            ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
            var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
          })();

        </script>
    </body>
</html>
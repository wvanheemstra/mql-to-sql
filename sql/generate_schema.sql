set @@group_concat_max_len := @@max_allowed_packet;

select      concat(
                '{\n'
            ,   '    "pdo": {\n'
            ,   '        "dsn": "mysql:dbname=',coalesce(schema(),''),';host=',@@hostname,';port=',@@port,'",\n'
            ,   '        "username": "', substring_index(current_user(), '@', 1),'",\n'
            ,   '        "password": "', substring_index(current_user(), '@', 1),'",\n'
            ,   '        "driver_options": {\n'
            ,   '        }\n'
            ,   '    },\n'
            ,   '    "explicit_type_conversion": true\n'
            ,   '\n}'
            ,   '{\n'
            ,   '    "domains": {\n'
            ,   group_concat(
                    json
                    order by table_schema
                    separator ',\n'
                )
            ,   '\n    }'
            ,   '\n}'
            ) json
from (            
select      table_schema
,           concat(
                '        "', table_schema, '": {\n'
            ,   '            "schema_name": "', table_schema,'",\n'
            ,   '            "types": {\n'
            ,   group_concat(
                    json
                    order by table_name
                    separator ',\n'
                )
            ,   '\n            }'
            ,   '\n        }'
            ) json
from (            
select      table_schema
,           table_name
,           concat(
                '                "', table_name, '": {\n'
            ,   '                    "table_name": "', table_name,'",\n'
            ,   '                    "properties": {\n'
            ,   group_concat(
                    case what when 'properties' then json else null end
                    order by column_name
                    separator ',\n'
                )
            ,   '\n                    },\n'
            ,   '                    "keys": {\n'
            ,   coalesce(group_concat(
                    case what when 'keys' then json else null end
                    order by column_name
                    separator ',\n'
                ),'')
            ,   '\n                    }'
            ,   '\n                }'
            ) json
from (
select      table_schema
,           table_name
,           'properties'                what
,           column_name
,           concat(
                '                        "', column_name, '": {\n'
            ,   '                            "column_name": "', column_name,'",\n'
            ,   '                            "nullable": ', case is_nullable 
                                                                when 'YES' then 'true'
                                                                else 'false'
                                                            end
                                                                ,',\n'
            ,   '                            "type": "/type/'
            ,   case
                    when data_type in (
                        'bit'
                    ) then 'boolean'
                    when data_type in (
                        'blob'
                    ,   'mediumblob'
                    ,   'longblob'
                    ,   'tinyblob'
                    ) then 'content'
                    when data_type in (
                        'datetime'
                    ,   'timestamp'
                    ) then 'datetime'
                    when data_type in (
                        'float'
                    ,   'decimal'
                    ,   'double'
                    ) then 'float'
                    when data_type in (
                        'bigint'
                    ,   'int'
                    ,   'mediumint'
                    ,   'smallint'
                    ,   'tinyint'
                    ,   'year'
                    ) then 'int'
                    when data_type in (
                        'binary'
                    ,   'varbinary'
                    ) then 'rawstring'
                    when data_type in (
                        'char'
                    ,   'enum'
                    ,   'mediumtext'
                    ,   'longtext'
                    ,   'set'
                    ,   'tinytext'
                    ,   'text'
                    ,   'varchar'
                    ) then 'text'
                    else data_type
                end, '"\n'
            ,   '                        }'
            )    json
from        information_schema.columns
where       table_schema != 'information_schema'
union all
select      case dir.direction 
                when 'referencing->referenced' then fcs.table_schema
                when 'referenced<-referencing' then ucs.table_schema 
            end table_schema
,           case dir.direction 
                when 'referencing->referenced' then fcs.table_name
                when 'referenced<-referencing' then ucs.table_name
            end table_name
,           'properties'                what
,           fcs.constraint_name
,           concat(
                '                        "', fcs.constraint_name, '": {\n'
            ,   '                            "type": "/', case dir.direction 
                                                              when 'referencing->referenced' then ucs.table_schema
                                                              when 'referenced<-referencing' then fcs.table_schema 
                                                          end
                                                   , '/', case dir.direction 
                                                              when 'referencing->referenced' then ucs.table_name
                                                              when 'referenced<-referencing' then fcs.table_name
                                                          end, '",\n'
            ,   '                            "direction": "',dir.direction,'",\n'
            ,   '                            "join_condition": [\n'
            ,                                    group_concat(
                '                                {\n'
            ,   '                                    "referencing_column": "',fkc.column_name,'",\n'
            ,   '                                    "referenced_column": "',fkc.referenced_column_name,'"\n'
            ,   '                                }\n'
                                                     order by fkc.ordinal_position
                                                 )
            ,   '                            ],\n'
            ,   '                            "nullable": ', case max(IS_NULLABLE) 
                                                                when 'YES' then 'true' 
                                                                else 'false' 
                                                            end,'\n' 
            ,   '                        }'
            )   json
from        (
            select 'referencing->referenced' direction
            union all
            select 'referenced<-referencing'
            )  dir
cross join  information_schema.table_constraints         fcs
inner join  information_schema.referential_constraints   rcs
on          fcs.constraint_schema                      = rcs.constraint_schema
and         fcs.constraint_name                        = rcs.constraint_name
inner join  information_schema.table_constraints         ucs
on          rcs.unique_constraint_schema               = ucs.constraint_schema
and         rcs.unique_constraint_name                 = ucs.constraint_name
and         rcs.referenced_table_name                  = ucs.table_name
inner join  information_schema.key_column_usage          fkc
on          fcs.constraint_schema                      = fkc.constraint_schema
and         fcs.constraint_name                        = fkc.constraint_name
and         fcs.table_schema                           = fkc.table_schema
and         fcs.table_name                             = fkc.table_name
and         ucs.table_schema                           = fkc.referenced_table_schema
and         ucs.table_name                             = fkc.referenced_table_name
inner join  information_schema.columns                   col
on          fkc.table_schema                           = col.table_schema
and         fkc.table_name                             = col.table_name
and         fkc.column_name                            = col.column_name
where       fcs.table_schema != 'information_schema'
group by    table_schema
,           table_name
,           fcs.constraint_name
,           dir.direction 
union all 
select      ucs.table_schema
,           ucs.table_name
,           'keys' 
,           ucs.constraint_name
,           concat(
                '                        "', ucs.constraint_name,'": {\n'
            ,   '                            "type": "', ucs.constraint_type,'",\n'
            ,   '                            "columns": ['
            ,   group_concat(
                    '"',kcu.column_name,'"'
                    order by kcu.ordinal_position
                    separator ','
                )
            ,   ']\n'
            ,   '                        }'
            )
from        information_schema.table_constraints         ucs
inner join  information_schema.key_column_usage          kcu
on          ucs.constraint_schema = kcu.constraint_schema
and         ucs.constraint_name   = kcu.constraint_name
and         ucs.table_schema      = kcu.table_schema
and         ucs.table_name        = kcu.table_name
and         ucs.constraint_type   in ('PRIMARY KEY', 'UNIQUE')
group by    ucs.table_schema
,           ucs.table_name
,           ucs.constraint_name
) cols
group by    table_schema
,           table_name
) tabs
group by    table_schema
) s
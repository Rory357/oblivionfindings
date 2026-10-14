package localui
import (
 "bytes"
 "strings"
 "testing"
)
func TestServiceActionPipeContract(t *testing.T){hash:=strings.Repeat("a",64);token:=strings.Repeat("b",64);poll:=`{"version":1,"operation":"service_action_poll","expected_origin_hash":"`+hash+`"}`;answer:=`{"version":1,"operation":"service_action_decide","expected_origin_hash":"`+hash+`","attempt_uuid":"cccccccc-cccc-4ccc-8ccc-cccccccccccc","payload_hash":"`+hash+`","consent_token":"`+token+`","decision":"allow"}`;for _,raw:=range []string{poll,answer}{if _,e:=readRequest(bytes.NewReader(frame(raw)));e!=nil{t.Fatal(e)}};for _,raw:=range []string{strings.Replace(answer,`"decision":"allow"`,`"decision":"execute"`,1),strings.Replace(answer,`"version":1`,`"version":1,"version":1`,1),strings.Replace(poll,`"version":1`,`"version":1,"staff_token":"private"`,1),strings.Replace(answer,`"attempt_uuid"`,`"device_id"`,1),strings.Replace(answer,hash,strings.Repeat("A",64),1)}{if _,e:=readRequest(bytes.NewReader(frame(raw)));e==nil{t.Fatal("unsafe action IPC accepted")}}}

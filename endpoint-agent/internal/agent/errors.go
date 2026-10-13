package agent

import "os"

func osNotExist(err error) bool { return os.IsNotExist(err) }
